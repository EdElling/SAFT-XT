<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Validation\RecordChecker;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\Api\RecordRejected;
use App\Support\AppLocale;
use DateTimeImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Datenimport in eine bestehende Tabelle des Table-Designers: legt nur neue Datensätze an (Ändern ist nicht
 * vorgesehen). Spalten werden über die Überschrift zugeordnet (Bezeichnung in einer der Sprachen oder technischer
 * Name), Werte so gelesen, wie der Export sie schreibt. Geprüft wird wie beim Erfassen; alles oder nichts.
 *
 * Gespeichert wird Datensatz für Datensatz über das Modell – so greifen Vorgaben (Übernehmen aus, fortlaufend),
 * berechnete Felder und Summen in übergeordneten Datensätzen. Ins Änderungsprotokoll kommt ein Eintrag „importiert“.
 */
final class RecordImporter
{
    /** Höchstens so viele Fehlermeldungen; die übrigen werden nur gezählt. */
    public const int MAX_MESSAGES = 50;

    /** Kennzeichen eines Pflichtfelds in der Überschrift der Vorlage. */
    private const string REQUIRED_MARK = ' *';

    public function __construct(
        private readonly DynamicDataNamespace $namespace,
        private readonly RecordChecker $checker,
    ) {}

    /**
     * Felder, die sich importieren lassen: aktiv, keine berechneten Felder, keine Systemspalten.
     *
     * @return list<CustomField>
     */
    public function fields(CustomTable $table): array
    {
        return $table->fields()->where('is_active', true)->orderBy('sort_order')->get()
            // Berechnete Felder füllt SAFT, Dateien lassen sich nicht aus einer Tabelle importieren.
            ->reject(fn (CustomField $field): bool => in_array($field->type, [FieldType::Computed, FieldType::File], true))
            ->values()
            ->all();
    }

    /** Leere Excel-Vorlage mit allen importierbaren Feldern; Pflichtfelder mit „ *“. Pfad einer temporären Datei. */
    public function template(CustomTable $table): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-template-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', (string) $table->label) ?: 'Daten', 0, 31));
        $writer->addRow(Row::fromValues(array_map(
            fn (CustomField $field): string => $field->label.($field->is_required ? self::REQUIRED_MARK : ''),
            $this->fields($table),
        )));
        $writer->close();

        return $path;
    }

    /** Prüft die Datei vollständig, ohne etwas zu speichern. */
    public function analyze(CustomTable $table, string $path, string $fileName): RecordImportAnalysis
    {
        $format = ImportFormat::fromFileName($fileName)
            ?? throw new BusinessRuleException(__('import.problems.unsupported_format', ['ext' => pathinfo($fileName, PATHINFO_EXTENSION) ?: '–']));
        $maxBytes = (int) config('dynamic_data.import.max_file_mb') * 1024 * 1024;

        if (filesize($path) > $maxBytes) {
            throw new BusinessRuleException(__('import.problems.file_too_large', ['max' => (int) config('dynamic_data.import.max_file_mb')]));
        }

        $reader = new SpreadsheetReader($path, $format);
        $fields = $this->fields($table);
        $columns = null;
        $ignored = [];
        $rows = [];
        $messages = [];
        $errorCount = 0;
        $unique = [];
        $lookups = [];
        $maxRows = (int) config('dynamic_data.import.max_record_rows');

        $fail = function (int $line, ?CustomField $field, string $message) use (&$messages, &$errorCount): void {
            $errorCount++;

            if (count($messages) < self::MAX_MESSAGES) {
                $messages[] = $field === null
                    ? __('import.records.row_error', ['row' => $line, 'message' => $message])
                    : __('import.records.cell_error', ['row' => $line, 'column' => $field->label, 'message' => $message]);
            }
        };

        foreach ($reader->rows() as $line => $cells) {
            if ($columns === null) {
                [$columns, $ignored] = $this->mapHeaders($cells, $fields);

                continue;
            }

            if ($this->isEmptyRow($cells)) {
                continue;
            }

            if (count($rows) >= $maxRows) {
                throw new BusinessRuleException(__('import.records.too_many_rows', ['max' => $maxRows]));
            }

            $values = [];

            foreach ($columns as $index => $field) {
                $raw = $cells[$index] ?? null;

                if ($raw instanceof CellError) {
                    $fail($line, $field, __('import.records.cell_error_value', ['value' => $raw->value]));

                    continue;
                }

                try {
                    $values[$field->name] = $this->value($table, $field, $raw, $lookups);
                } catch (BusinessRuleException $e) {
                    $fail($line, $field, $e->getMessage());
                }
            }

            foreach ($this->checker->check($table, $values, unique: $unique) as [$field, $message]) {
                $fail($line, $field, $message);
            }

            $rows[$line] = $values;
        }

        if ($columns === null) {
            throw new BusinessRuleException(__('import.records.empty_file'));
        }

        $missing = array_values(array_map(
            fn (CustomField $field): string => $field->label,
            array_filter($fields, fn (CustomField $field): bool => $field->is_required && ! in_array($field, $columns, true) && ! $this->checker->hasDefault($table, $field)),
        ));

        return new RecordImportAnalysis(
            columns: array_values(array_map(fn (CustomField $field): string => $field->label, $columns)),
            ignored: $ignored,
            missingRequired: $missing,
            rows: $rows,
            messages: $messages,
            errorCount: $errorCount,
        );
    }

    /**
     * Legt alle Datensätze an – nur wenn die Prüfung keine Fehler fand. Gibt die Zahl der neuen Datensätze zurück.
     */
    public function import(CustomTable $table, string $path, string $fileName, ?User $user): int
    {
        $analysis = $this->analyze($table, $path, $fileName);

        if (! $analysis->isValid()) {
            throw new BusinessRuleException(__('import.records.invalid'));
        }

        $connection = $this->namespace->connection();
        $connection->beginTransaction();

        // Kein Eintrag je Datensatz: ein Eintrag „importiert“ fasst den Import zusammen.
        activity()->disableLogging();

        try {
            foreach ($analysis->rows as $line => $values) {
                try {
                    DynamicRecord::forTableName($table->name)->create($values);
                } catch (RecordRejected $e) {
                    // Abgelehnt von einem Plugin (Ereignis beim Speichern): als Fehler der Zeile melden.
                    throw new BusinessRuleException(__('import.records.row_error', ['row' => $line, 'message' => $e->getMessage()]), previous: $e);
                }
            }

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        } finally {
            activity()->enableLogging();
        }

        activity('data')
            ->performedOn($table)
            ->causedBy($user)
            ->event('imported')
            ->withChanges(['attributes' => [
                'datei' => $fileName,
                'datensaetze' => count($analysis->rows),
                'spalten' => count($analysis->columns),
            ]])
            ->log('imported');

        return count($analysis->rows);
    }

    // --- intern ------------------------------------------------------------------------

    /**
     * Überschriften → Felder. Erkannt werden die Bezeichnung in jeder Sprache und der technische Name (ohne Groß-
     * und Kleinschreibung, „ *“ der Vorlage wird ignoriert).
     *
     * @param  list<mixed>  $cells
     * @param  list<CustomField>  $fields
     * @return array{0: array<int, CustomField>, 1: list<string>}
     */
    private function mapHeaders(array $cells, array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            $names[mb_strtolower($field->name)] = $field;

            foreach ((array) $field->getTranslations('label') as $label) {
                $names[mb_strtolower(trim((string) $label))] ??= $field;
            }
        }

        $columns = [];
        $ignored = [];

        foreach ($cells as $index => $cell) {
            $header = trim(ImportValueParser::text($cell));

            if ($header === '') {
                continue;
            }

            $key = mb_strtolower(trim((string) preg_replace('/\s*\*$/', '', $header)));
            $field = $names[$key] ?? null;

            if ($field === null || in_array($field, $columns, true)) {
                $ignored[] = $header;

                continue;
            }

            $columns[$index] = $field;
        }

        return [$columns, $ignored];
    }

    /** @param  list<mixed>  $cells */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell instanceof CellError || trim(ImportValueParser::text($cell)) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Zellwert → gespeicherter Wert des Felds (null = leer).
     *
     * @param  array<string, array<string, list<int|string>>>  $lookups  Anzeigenamen verknüpfter Datensätze (Cache)
     */
    private function value(CustomTable $table, CustomField $field, mixed $raw, array &$lookups): mixed
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $text = trim(ImportValueParser::text($raw));
        $options = $field->optionsArray();

        return match ($field->type) {
            FieldType::String, FieldType::Text => $text,
            FieldType::Integer => $this->integer($raw, $text),
            FieldType::Decimal => $this->decimal($raw, $text),
            FieldType::Boolean => $this->boolean($raw, $text),
            FieldType::Date => $this->date($raw, $text, false),
            FieldType::DateTime => $this->date($raw, $text, true),
            FieldType::Select => $this->choice((array) ($options['options'] ?? []), $text),
            FieldType::Relation => $this->relation($field, $text, $lookups),
            default => $text,
        };
    }

    private function integer(mixed $raw, string $text): int
    {
        $number = is_int($raw) || is_float($raw) ? ImportValueParser::float((float) $raw) : $this->number($text);

        if ($number === null || $number['scale'] > 0 || $number['digits'] > ImportValueParser::MAX_INTEGER_DIGITS) {
            throw new BusinessRuleException(__('import.records.not_integer', ['value' => $text]));
        }

        return (int) $number['value'];
    }

    private function decimal(mixed $raw, string $text): string
    {
        $number = is_int($raw) || is_float($raw) ? ImportValueParser::float((float) $raw) : $this->number($text);

        return $number['value'] ?? throw new BusinessRuleException(__('import.records.not_number', ['value' => $text]));
    }

    /** @return array{value: string, digits: int, scale: int, leading_zero: bool}|null */
    private function number(string $text): ?array
    {
        // Schweizer und französische Tausendertrennzeichen (1'234.50, 1 234,50) zuerst entfernen.
        $text = str_replace(["'", '’', "\u{202F}", "\u{00A0}"], '', $text);

        // Schreibweise der Oberfläche zuerst (Englisch und Schweiz 1234.50; sonst 1.234,50), dann die andere.
        $formats = app()->getLocale() === 'en' || AppLocale::region() === 'CH'
            ? [ImportValueParser::FORMAT_EN, ImportValueParser::FORMAT_DE]
            : [ImportValueParser::FORMAT_DE, ImportValueParser::FORMAT_EN];

        foreach ($formats as $format) {
            if (($number = ImportValueParser::number($text, $format)) !== null) {
                return $number;
            }
        }

        return null;
    }

    private function boolean(mixed $raw, string $text): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $word = mb_strtolower($text);

        foreach (['de', 'en', 'fr', 'it'] as $locale) {
            if ($word === mb_strtolower((string) __('common.yes', [], $locale))) {
                return true;
            }

            if ($word === mb_strtolower((string) __('common.no', [], $locale))) {
                return false;
            }
        }

        return match (true) {
            in_array($word, ['1', 'x'], true) => true,
            $word === '0' => false,
            default => ImportValueParser::boolean($text) ?? throw new BusinessRuleException(__('import.records.not_boolean', ['value' => $text])),
        };
    }

    private function date(mixed $raw, string $text, bool $withTime): string
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }

        // Excel-Datum ohne Datumsformat: Tage seit dem 30.12.1899 (Bruchteil = Uhrzeit).
        if ((is_int($raw) || is_float($raw)) && $raw > 0 && $raw < 2958466) {
            $date = (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor($raw).' days')->modify('+'.(int) round(fmod((float) $raw, 1) * 86400).' seconds');

            return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }

        // Export auf Englisch, Französisch, Italienisch: TT/MM/JJJJ.
        $parsed = ImportValueParser::date((string) preg_replace('#^(\d{1,2})/(\d{1,2})/(\d{4})#', '$1.$2.$3', $text));

        if ($parsed === null) {
            throw new BusinessRuleException(__('import.records.not_date', ['value' => $text]));
        }

        return $withTime ? (strlen($parsed['value']) === 10 ? $parsed['value'].' 00:00:00' : $parsed['value']) : substr($parsed['value'], 0, 10);
    }

    /** Auswahlliste: gespeicherter Wert oder Bezeichnung in einer der Sprachen. */
    private function choice(array $options, string $text): string
    {
        $needle = mb_strtolower($text);

        foreach ($options as $option) {
            if (! is_array($option) || ! isset($option['value'])) {
                continue;
            }

            $labels = is_array($option['label'] ?? null) ? $option['label'] : [(string) ($option['label'] ?? '')];

            if (mb_strtolower((string) $option['value']) === $needle || in_array($needle, array_map(fn ($l): string => mb_strtolower(trim((string) $l)), $labels), true)) {
                return (string) $option['value'];
            }
        }

        throw new BusinessRuleException(__('import.records.not_choice', ['value' => $text]));
    }

    /**
     * Verknüpfung: Anzeigename (wie im Export) oder Nummer des verknüpften Datensatzes.
     *
     * @param  array<string, array<string, list<int|string>>>  $lookups
     */
    private function relation(CustomField $field, string $text, array &$lookups): int|string
    {
        $options = $field->optionsArray();
        $target = (string) ($options['table'] ?? '');
        $model = DynamicRecord::forTableName($target);

        if (! isset($lookups[$target.'|'.$field->name])) {
            $map = [];

            foreach ($model->newQuery()->get() as $record) {
                $label = RelationDisplay::label($record, $options);

                if ($label !== null) {
                    $map[mb_strtolower(trim($label))][] = $record->getKey();
                }
            }

            $lookups[$target.'|'.$field->name] = $map;
        }

        $ids = $lookups[$target.'|'.$field->name][mb_strtolower($text)] ?? [];

        if (count($ids) === 1) {
            return $ids[0];
        }

        if (count($ids) > 1) {
            throw new BusinessRuleException(__('import.records.ambiguous', ['value' => $text]));
        }

        if (ctype_digit($text) && $model->newQuery()->whereKey((int) $text)->exists()) {
            return (int) $text;
        }

        throw new BusinessRuleException(__('import.records.not_found', ['value' => $text]));
    }
}
