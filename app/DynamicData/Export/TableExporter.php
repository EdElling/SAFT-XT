<?php

declare(strict_types=1);

namespace App\DynamicData\Export;

use App\DynamicData\Schema\FieldType;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\Models\CustomField;
use App\Models\DynamicRecord;
use App\Support\AppLocale;
use App\Support\Translations;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Schreibt eine gefilterte und sortierte Abfrage in eine temporäre Datei.
 * Die Werte entsprechen der Liste: Bezeichnungen statt gespeicherter Auswahlwerte,
 * Anzeigewerte bei Verknüpfungen, aber ungekürzte Texte.
 */
final class TableExporter
{
    private const int CHUNK = 1000;

    /** Mehr Zeichen fasst eine Excel-Zelle nicht. */
    private const int XLSX_MAX_CELL_LENGTH = 32767;

    /** Mehr Stellen speichert Excel nicht exakt; längere Zahlen bleiben daher Text. */
    private const int XLSX_MAX_NUMBER_DIGITS = 15;

    /** Der übliche Grenzwert von 30 Sekunden reicht für große Exporte nicht. */
    private const int TIME_LIMIT_SECONDS = 300;

    /** @var array<string, Style> Ein Style je Zahlenformat: Pro Zelle neu wäre deutlich langsamer. */
    private array $styles = [];

    /**
     * @param  Builder<DynamicRecord>  $query
     * @param  list<CustomField>  $fields
     * @return string Pfad der temporären Datei; Aufräumen liegt beim Aufrufer
     */
    public function write(Builder $query, array $fields, ExportFormat $format, string $title): string
    {
        set_time_limit(self::TIME_LIMIT_SECONDS);

        $path = (string) tempnam(sys_get_temp_dir(), 'saft-export-');

        try {
            match ($format) {
                ExportFormat::Xlsx => $this->writeXlsx($query, $fields, $title, $path),
                ExportFormat::Csv => $this->writeCsv($query, $fields, $path),
            };
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * @param  Builder<DynamicRecord>  $query
     * @param  list<CustomField>  $fields
     */
    private function writeXlsx(Builder $query, array $fields, string $title, string $path): void
    {
        $writer = new XlsxWriter(new XlsxOptions);
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName($this->sheetName($title));

            if ($fields !== []) {
                // Ohne Breite zeigt Excel Datumswerte als „#####“.
                $writer->getCurrentSheet()->setColumnWidthForRange(20, 1, count($fields));
            }

            $writer->addRow(new Row(
                array_map(fn (CustomField $field): Cell => $this->xlsxText($field->label), $fields),
                (new Style)->setFontBold(),
            ));

            foreach ($this->records($query) as $record) {
                $writer->addRow(new Row(array_map(fn (CustomField $field): Cell => $this->xlsxCell($field, $record), $fields)));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  Builder<DynamicRecord>  $query
     * @param  list<CustomField>  $fields
     */
    private function writeCsv(Builder $query, array $fields, string $path): void
    {
        // Trennzeichen je Sprache und BOM: So öffnet Excel die Datei direkt in Spalten.
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = AppLocale::setting('csv_delimiter');
        $options->SHOULD_ADD_BOM = true;

        $writer = new CsvWriter($options);
        $writer->openToFile($path);

        try {
            $writer->addRow(new Row(array_map(fn (CustomField $field): Cell => $this->csvText($field->label), $fields)));

            foreach ($this->records($query) as $record) {
                $writer->addRow(new Row(array_map(fn (CustomField $field): Cell => $this->csvCell($field, $record), $fields)));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * Seitenweise statt Cursor: pdo_pgsql lädt sonst das gesamte Ergebnis in den Speicher.
     * Die ID als letztes Sortierkriterium hält die Reihenfolge über die Seiten hinweg stabil.
     *
     * @param  Builder<DynamicRecord>  $query
     * @return iterable<DynamicRecord>
     */
    private function records(Builder $query): iterable
    {
        $model = $query->getModel();

        // Ohne eindeutigen Schlüssel (Views, Tabellen ohne Primärschlüssel) wären Blöcke per Versatz
        // nicht stabil; dann in einem Durchgang lesen.
        if ($model instanceof DynamicRecord && ! $model->hasUniqueKey()) {
            return (clone $query)->cursor();
        }

        return (clone $query)->orderBy($model->qualifyColumn($model->getKeyName()))->lazy(self::CHUNK);
    }

    private function xlsxCell(CustomField $field, DynamicRecord $record): Cell
    {
        $state = $record->getAttribute($field->name);

        return match (true) {
            $field->type === FieldType::Integer && $state !== null => $this->xlsxNumber((string) $state, '0'),
            $field->valueType() === FieldType::Decimal && $state !== null => $this->xlsxNumber((string) $state, $this->decimalFormat($field)),
            $field->type === FieldType::Date && $state instanceof DateTimeInterface => new Cell\DateTimeCell($state, $this->format(AppLocale::setting('xlsx_date'))),
            $field->type === FieldType::DateTime && $state instanceof DateTimeInterface => new Cell\DateTimeCell($state, $this->format(AppLocale::setting('xlsx_datetime'))),
            default => $this->xlsxText($this->text($field, $record)),
        };
    }

    private function xlsxNumber(string $number, string $format): Cell
    {
        return strlen(preg_replace('/\D/', '', $number) ?? '') > self::XLSX_MAX_NUMBER_DIGITS
            ? $this->xlsxText($number)
            : new Cell\NumericCell(str_contains($number, '.') ? (float) $number : (int) $number, $this->format($format));
    }

    private function format(string $format): Style
    {
        return $this->styles[$format] ??= (new Style)->setFormat($format);
    }

    /** StringCell statt Cell::fromValue(): Dort würde ein Text mit „=“ am Anfang zur Formel. */
    private function xlsxText(?string $value): Cell
    {
        return $value === null || $value === ''
            ? new Cell\EmptyCell(null, null)
            : new Cell\StringCell(mb_substr($value, 0, self::XLSX_MAX_CELL_LENGTH), null);
    }

    private function csvCell(CustomField $field, DynamicRecord $record): Cell
    {
        $state = $record->getAttribute($field->name);

        return match (true) {
            $state === null && in_array($field->valueType(), [FieldType::Integer, FieldType::Decimal, FieldType::Date, FieldType::DateTime], true) => new Cell\EmptyCell(null, null),
            $field->type === FieldType::Integer => new Cell\StringCell((string) $state, null),
            $field->valueType() === FieldType::Decimal => new Cell\StringCell(str_replace('.', AppLocale::setting('csv_decimal'), (string) $state), null),
            $field->type === FieldType::Date && $state instanceof DateTimeInterface => new Cell\StringCell((string) AppLocale::date($state), null),
            $field->type === FieldType::DateTime && $state instanceof DateTimeInterface => new Cell\StringCell((string) AppLocale::dateTime($state), null),
            default => $this->csvText($this->text($field, $record)),
        };
    }

    /** Excel führt CSV-Werte mit diesen Anfangszeichen als Formel aus, daher entschärfen. */
    private function csvText(?string $value): Cell
    {
        if ($value === null || $value === '') {
            return new Cell\EmptyCell(null, null);
        }

        return new Cell\StringCell(preg_match('/^[=+\-@\t\r\n]/', $value) === 1 ? "'".$value : $value, null);
    }

    private function text(CustomField $field, DynamicRecord $record): ?string
    {
        $state = $record->getAttribute($field->name);

        $text = match ($field->valueType()) {
            FieldType::Boolean => $state === null ? null : ($state ? __('common.yes') : __('common.no')),
            FieldType::Select => Translations::pick(collect((array) ($field->optionsArray()['options'] ?? []))->firstWhere('value', $state)['label'] ?? null, (string) $state),
            FieldType::Relation => DynamicTableBuilder::relationLabel($field, $record),
            default => $state,
        };

        return $text === null ? null : (string) $text;
    }

    private function decimalFormat(CustomField $field): string
    {
        $scale = (int) ($field->optionsArray()['scale'] ?? 2);

        return $scale > 0 ? '#,##0.'.str_repeat('0', $scale) : '#,##0';
    }

    /** Excel erlaubt 31 Zeichen und einige Sonderzeichen nicht als Blattname. */
    private function sheetName(string $title): string
    {
        $name = trim(mb_substr((string) preg_replace('/[\\\\\/?*:\[\]]/u', ' ', $title), 0, 31), " '");

        return $name === '' ? 'Export' : $name;
    }
}
