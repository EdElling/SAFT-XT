<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prüft, ob eine CSV- oder Excel-Datei eine saubere, importierbare Liste ist, und leitet
 * Feldnamen und -typen ab. Sammelt alle Befunde (mit Zeile und Spalte), statt beim ersten
 * abzubrechen. Gleiche Befunde in vielen Zeilen werden nach MAX_PER_CODE zusammengefasst.
 */
final class ImportAnalyzer
{
    private const int MAX_PER_CODE = 10;

    /** MySQL/MariaDB: höchstens 65'535 Byte deklarierte Zeilengröße; Reserve für Systemspalten. */
    private const int MAX_ROW_BYTES = 65_535 - 200;

    private const int MAX_HEADER_LENGTH = 255;

    private const string YEAR_TITLE = '/(jahr|year|année|annee|anno)\\b/iu';

    private const string SUMMARY_PATTERN = '/^(summe|zwischensumme|gesamt|gesamtsumme|total|subtotal|sum)\b/iu';

    /** @var list<ImportProblem> */
    private array $problems = [];

    /** @var array<string, int> */
    private array $perCode = [];

    public function __construct(private readonly ColumnNameDeriver $names) {}

    /**
     * @param  string  $path  Datei auf dem Server
     * @param  string  $fileName  ursprünglicher Dateiname (bestimmt das Format und den Tabellennamen)
     * @param  string|null  $sheet  Blatt einer Excel-Datei; null = erstes sichtbares
     */
    public function analyze(string $path, string $fileName, ?string $sheet = null): ImportAnalysis
    {
        $this->problems = [];
        $this->perCode = [];
        $format = ImportFormat::fromFileName($fileName);

        if ($format === null) {
            $this->error('unsupported_format', ['ext' => pathinfo($fileName, PATHINFO_EXTENSION) ?: '–']);

            return $this->result($format);
        }

        $maxBytes = (int) config('dynamic_data.import.max_file_mb', 20) * 1024 * 1024;

        if ((int) @filesize($path) > $maxBytes) {
            $this->error('file_too_large', ['max' => config('dynamic_data.import.max_file_mb', 20)]);

            return $this->result($format);
        }

        try {
            $reader = new SpreadsheetReader($path, $format);
            $sheet = $reader->resolveSheet($sheet);
            $columns = $this->read($reader, $sheet, $rowCount);
            $merged = $reader->mergedCells($sheet);
        } catch (Throwable $e) {
            Log::warning('Import-Datei nicht lesbar', ['file' => $fileName, 'error' => $e->getMessage()]);
            $this->problems = [];
            $this->error('unreadable');

            return $this->result($format);
        }

        if ($merged !== []) {
            $this->error('merged_cells', ['ranges' => implode(', ', array_slice($merged, 0, self::MAX_PER_CODE))]);
        }

        $this->checkRowSize($columns);

        return $this->result(
            $format,
            $columns,
            $rowCount,
            $sheet,
            array_column($reader->sheets(), 'name'),
            $reader->delimiter,
            $reader->encoding,
            $this->names->table($format === ImportFormat::Xlsx && count($reader->sheets()) > 1 && $sheet !== null ? $sheet : $fileName),
        );
    }

    /**
     * @return list<ImportColumn>
     */
    private function read(SpreadsheetReader $reader, ?string $sheet, ?int &$rowCount): array
    {
        $rowCount = 0;
        $maxRows = (int) config('dynamic_data.import.max_rows', 50_000);
        $header = null;
        $headerCells = 0;
        $profiles = [];
        $blank = [];
        $lastRow = null;
        $secondRowFilled = null;

        foreach ($reader->rows($sheet) as $number => $cells) {
            if ($header === null) {
                if ($this->filled($cells) === 0) {
                    $this->error('header_not_first_row');

                    return [];
                }

                $header = $this->trimTrailing($cells);
                $headerCells = count($cells);
                $profiles = array_map(fn (): ColumnProfile => new ColumnProfile, $header);

                continue;
            }

            if ($this->filled($cells) === 0) {
                $blank[] = $number;

                continue;
            }

            // Leere Zeilen mitten in der Liste trennen meist Blöcke – keine saubere Liste.
            foreach ($blank as $blankRow) {
                $this->error('blank_row', ['row' => $blankRow]);
            }

            $blank = [];

            if (++$rowCount > $maxRows) {
                $this->error('too_many_rows', ['max' => number_format($maxRows, 0, ',', '\'')]);

                break;
            }

            $secondRowFilled ??= $this->filled($cells);
            $this->checkRow($reader, $number, $cells, count($header), $headerCells);

            foreach ($profiles as $index => $profile) {
                $value = $cells[$index] ?? null;

                if ($value instanceof CellError) {
                    $this->error('cell_error', ['cell' => ImportColumn::letterFor($index).$number, 'value' => $value->value]);

                    continue;
                }

                $profile->add($value);
            }

            $lastRow = [$number, $cells[0] ?? null];
        }

        if ($header === null) {
            $this->error('empty_file');

            return [];
        }

        // Titelzeile über der Liste: Zeile 1 hat nur eine Zelle, die Daten darunter mehrere.
        if ($this->filled($header) === 1 && ($secondRowFilled ?? 0) > 1) {
            $this->error('title_row');
        }

        if ($rowCount === 0) {
            $this->warning('no_data');
        }

        if ($lastRow !== null && is_string($lastRow[1]) && preg_match(self::SUMMARY_PATTERN, trim($lastRow[1])) === 1 && $rowCount > 1) {
            $this->warning('summary_row', ['row' => $lastRow[0], 'value' => trim($lastRow[1])]);
        }

        return $this->columns($header, $profiles, $this->preferredNumberFormat($reader));
    }

    /**
     * Zahlenformat, wenn eine Spalte in beiden passt ("1.234"): Komma als CSV-Trennzeichen schließt
     * das Dezimalkomma praktisch aus; Semikolon ist typisch für deutsches Excel. Bei Excel-Dateien
     * (Zahlen als Text) entscheidet die Sprache.
     */
    private function preferredNumberFormat(SpreadsheetReader $reader): string
    {
        return match (true) {
            $reader->format === ImportFormat::Csv => $reader->delimiter === ',' ? ImportValueParser::FORMAT_EN : ImportValueParser::FORMAT_DE,
            app()->getLocale() === 'en' => ImportValueParser::FORMAT_EN,
            default => ImportValueParser::FORMAT_DE,
        };
    }

    /** @param  list<mixed>  $cells */
    private function checkRow(SpreadsheetReader $reader, int $number, array $cells, int $width, int $headerCells): void
    {
        if ($reader->format === ImportFormat::Csv && count($cells) !== $headerCells) {
            $this->error('column_count', ['row' => $number, 'actual' => count($cells), 'expected' => $headerCells]);

            return;
        }

        foreach (array_slice($cells, $width, null, true) as $index => $value) {
            if ($value !== null && $value !== '') {
                $this->error('values_without_header', ['row' => $number, 'column' => ImportColumn::letterFor($index)]);

                return;
            }
        }
    }

    /**
     * @param  list<mixed>  $header
     * @param  list<ColumnProfile>  $profiles
     * @return list<ImportColumn>
     */
    private function columns(array $header, array $profiles, string $numberFormat): array
    {
        $max = (int) config('dynamic_data.import.max_columns', 200);

        if (count($header) > $max) {
            $this->error('too_many_columns', ['count' => count($header), 'max' => $max]);

            return [];
        }

        $titles = [];
        $columns = [];

        foreach ($header as $index => $value) {
            $letter = ImportColumn::letterFor($index);
            $title = trim((string) preg_replace('/\s+/u', ' ', $value instanceof DateTimeInterface || ! is_string($value)
                ? ($value === null || $value instanceof CellError ? '' : ImportValueParser::text($value))
                : $value));

            if ($title === '') {
                $this->error('header_missing', ['column' => $letter]);

                continue;
            }

            if (mb_strlen($title) > self::MAX_HEADER_LENGTH) {
                $this->error('header_too_long', ['column' => $letter, 'max' => self::MAX_HEADER_LENGTH]);

                continue;
            }

            $titles[mb_strtolower($title)] ??= ['title' => $title, 'letters' => []];
            $titles[mb_strtolower($title)]['letters'][] = $letter;
            $derived = $this->names->column($title);

            if ($derived === null) {
                $this->error('name_unusable', ['column' => $letter, 'title' => $title]);

                continue;
            }

            $guess = $profiles[$index]->guess($numberFormat);

            // „Jahr“ mit vierstelligen Ganzzahlen: ohne Tausendertrennzeichen anzeigen („2024“).
            if ($guess['type'] === FieldType::Integer && $profiles[$index]->looksLikeYears() && preg_match(self::YEAR_TITLE, $title) === 1) {
                $guess['options'][CustomField::GROUP_DIGITS] = false;
            }
            $notes = array_filter([$derived['note'], ...$guess['notes']]);

            $columns[] = new ImportColumn(
                index: $index,
                header: $title,
                name: $derived['name'],
                type: $guess['type'],
                options: $guess['options'],
                numberFormat: $guess['number_format'],
                notes: array_values(array_map(fn (string $note): string => __('import.notes.'.$note, [
                    'title' => $title, 'name' => $derived['name'],
                ]), $notes)),
                filled: $profiles[$index]->filled,
                empty: $profiles[$index]->empty,
            );
        }

        foreach ($titles as ['title' => $title, 'letters' => $letters]) {
            if (count($letters) > 1) {
                $this->error('duplicate_header', ['title' => $title, 'columns' => implode(', ', $letters)]);
            }
        }

        $byName = [];

        foreach ($columns as $column) {
            $byName[$column->name][] = $column;
        }

        foreach ($byName as $name => $same) {
            $letters = array_map(fn (ImportColumn $c): string => $c->letter(), $same);

            // Gleiche Titel meldet schon duplicate_header; hier nur verschiedene Titel mit gleichem Namen.
            if (count($same) > 1 && count(array_unique(array_map(fn (ImportColumn $c): string => mb_strtolower($c->header), $same))) > 1) {
                $this->error('name_collision', ['name' => $name, 'columns' => implode(', ', $letters)]);
            }
        }

        return $columns;
    }

    /** @param  list<ImportColumn>  $columns */
    private function checkRowSize(array $columns): void
    {
        $bytes = array_sum(array_map(fn (ImportColumn $c): int => match ($c->type) {
            FieldType::String => $c->options['length'] * 4 + 2,
            FieldType::Decimal => intdiv($c->options['precision'], 2) + 2,
            FieldType::Text => 12,
            FieldType::Boolean => 1,
            FieldType::Date => 3,
            default => 8,
        }, $columns));

        if ($bytes > self::MAX_ROW_BYTES) {
            $this->error('row_too_large');
        }
    }

    /** @param  list<mixed>  $cells */
    private function filled(array $cells): int
    {
        return count(array_filter($cells, fn (mixed $v): bool => $v !== null && ! (is_string($v) && trim($v) === '')));
    }

    /**
     * @param  list<mixed>  $cells
     * @return list<mixed>
     */
    private function trimTrailing(array $cells): array
    {
        while ($cells !== [] && (end($cells) === null || (is_string(end($cells)) && trim((string) end($cells)) === ''))) {
            array_pop($cells);
        }

        return $cells;
    }

    /** @param  array<string, mixed>  $params */
    private function error(string $code, array $params = []): void
    {
        $this->add(ImportProblem::ERROR, $code, $params);
    }

    /** @param  array<string, mixed>  $params */
    private function warning(string $code, array $params = []): void
    {
        $this->add(ImportProblem::WARNING, $code, $params);
    }

    /** @param  array<string, mixed>  $params */
    private function add(string $severity, string $code, array $params): void
    {
        $count = $this->perCode[$code] = ($this->perCode[$code] ?? 0) + 1;

        if ($count <= self::MAX_PER_CODE) {
            $this->problems[] = new ImportProblem($severity, $code, __('import.problems.'.$code, $params));
        }
    }

    /**
     * @param  list<ImportColumn>  $columns
     * @param  list<string>  $sheets
     */
    private function result(
        ?ImportFormat $format,
        array $columns = [],
        int $rowCount = 0,
        ?string $sheet = null,
        array $sheets = [],
        ?string $delimiter = null,
        ?string $encoding = null,
        ?string $tableName = null,
    ): ImportAnalysis {
        $problems = [];
        $remaining = array_count_values(array_map(fn (ImportProblem $p): string => $p->code, $this->problems));

        // Nach dem letzten gezeigten Befund einer Art: "… und N weitere".
        foreach ($this->problems as $problem) {
            $problems[] = $problem;

            if (--$remaining[$problem->code] === 0 && ($hidden = $this->perCode[$problem->code] - self::MAX_PER_CODE) > 0) {
                $problems[] = new ImportProblem($problem->severity, 'more', __('import.problems.more', ['count' => $hidden]));
            }
        }

        return new ImportAnalysis($format, $columns, $problems, $rowCount, $sheet, $sheets, $delimiter, $encoding, $tableName);
    }
}
