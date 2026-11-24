<?php

declare(strict_types=1);

namespace App\DynamicData\Export;

use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\Models\CustomField;
use App\Models\DynamicRecord;
use App\Support\AppLocale;
use App\Support\Translations;
use DateTimeInterface;
use RuntimeException;

/**
 * Schreibt Datensätze als CSV oder Excel in den Ordner einer Arbeit (Fundament 2, F3b) – blockweise und über mehrere
 * Prozesse hinweg: Jeder Schritt der Arbeit öffnet den Exporter, hängt seine Zeilen an die Teildatei an und schliesst
 * ihn; der letzte Schritt macht mit close() die fertige Datei daraus. Die Werte entsprechen der Liste: Bezeichnungen
 * statt gespeicherter Auswahlwerte, Anzeigewerte bei Verknüpfungen, aber ungekürzte Texte.
 */
final class TableExporter
{
    /** Mehr Stellen speichert Excel nicht exakt; längere Zahlen bleiben daher Text. */
    private const int XLSX_MAX_NUMBER_DIGITS = 15;

    private const string CSV_PART = 'export.part';

    private const string XLSX_PART = 'sheet.part';

    /** @var resource|null */
    private $csv = null;

    private ?XlsxStreamWriter $xlsx = null;

    /** @var list<string> Zahlen- und Datumsformate der Excel-Datei, Stil-Index = Position + 2 */
    private array $formats = [];

    /**
     * @param  list<CustomField>  $fields
     */
    private function __construct(
        private readonly ExportFormat $format,
        private readonly string $directory,
        private readonly array $fields,
        private readonly string $title,
    ) {}

    /**
     * Zum Anhängen öffnen; der Ordner muss existieren.
     *
     * @param  list<CustomField>  $fields
     */
    public static function open(ExportFormat $format, string $directory, array $fields, string $title): self
    {
        $exporter = new self($format, $directory, $fields, $title);

        if ($format === ExportFormat::Csv) {
            $handle = fopen($directory.'/'.self::CSV_PART, 'a');

            if ($handle === false) {
                throw new RuntimeException('Teildatei des Exports nicht beschreibbar.');
            }

            $exporter->csv = $handle;
        } else {
            $exporter->formats = self::formats($fields);
            $exporter->xlsx = new XlsxStreamWriter($directory.'/'.self::XLSX_PART, $exporter->formats);
        }

        return $exporter;
    }

    /** Überschriften (Zeile 1); CSV mit BOM, damit Excel die Datei direkt in Spalten öffnet. */
    public function header(): void
    {
        if ($this->csv !== null) {
            fwrite($this->csv, "\xEF\xBB\xBF");
            $this->csvLine(array_map(fn (CustomField $field): string => $this->csvText($field->label) ?? '', $this->fields));

            return;
        }

        $this->xlsx?->appendRow(1, array_map(fn (CustomField $field): XlsxCell => XlsxCell::text((string) $field->label, bold: true), $this->fields));
    }

    /**
     * Datensätze anhängen.
     *
     * @param  iterable<DynamicRecord>  $records
     * @param  int  $row  Zeilennummer des ersten Datensatzes in der Datei (Überschrift ist 1)
     * @return int Zahl der geschriebenen Datensätze
     */
    public function rows(iterable $records, int $row): int
    {
        $count = 0;

        foreach ($records as $record) {
            if ($this->csv !== null) {
                $this->csvLine(array_map(fn (CustomField $field): string => $this->csvCell($field, $record), $this->fields));
            } else {
                $this->xlsx?->appendRow($row + $count, array_map(fn (CustomField $field): ?XlsxCell => $this->xlsxCell($field, $record), $this->fields));
            }

            $count++;
        }

        return $count;
    }

    /** Schritt beenden; die Teildatei bleibt für den nächsten. */
    public function release(): void
    {
        if ($this->csv !== null) {
            fclose($this->csv);
            $this->csv = null;
        }
    }

    /** Aus der Teildatei die fertige Datei $file im Ordner machen; liefert ihren Pfad. */
    public function close(string $file): string
    {
        $this->release();
        $target = $this->directory.'/'.basename($file);

        if ($this->format === ExportFormat::Csv) {
            if (! rename($this->directory.'/'.self::CSV_PART, $target)) {
                throw new RuntimeException('Exportdatei nicht möglich.');
            }
        } else {
            $this->xlsx?->pack($target, $this->sheetName($this->title), count($this->fields));
        }

        return $target;
    }

    /**
     * Zahlen- und Datumsformate der Felder, jedes einmal.
     *
     * @param  list<CustomField>  $fields
     * @return list<string>
     */
    public static function formats(array $fields): array
    {
        $formats = [];

        foreach ($fields as $field) {
            $format = match (true) {
                $field->type === FieldType::Integer => '0',
                $field->valueType() === FieldType::Decimal => self::decimalFormat($field),
                $field->type === FieldType::Date => (string) AppLocale::setting('xlsx_date'),
                $field->type === FieldType::DateTime => (string) AppLocale::setting('xlsx_datetime'),
                default => null,
            };

            if ($format !== null && ! in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /** @param  list<string>  $values */
    private function csvLine(array $values): void
    {
        if ($this->csv === null || fputcsv($this->csv, $values, (string) AppLocale::setting('csv_delimiter'), '"', '') === false) {
            throw new RuntimeException('Teildatei des Exports nicht beschreibbar.');
        }
    }

    private function xlsxCell(CustomField $field, DynamicRecord $record): ?XlsxCell
    {
        $state = $record->getAttribute($field->name);

        return match (true) {
            $field->type === FieldType::Integer && $state !== null => $this->xlsxNumber((string) $state, '0'),
            $field->valueType() === FieldType::Decimal && $state !== null => $this->xlsxNumber((string) $state, self::decimalFormat($field)),
            $field->type === FieldType::Date && $state instanceof DateTimeInterface => XlsxCell::date($state, $this->style((string) AppLocale::setting('xlsx_date'))),
            $field->type === FieldType::DateTime && $state instanceof DateTimeInterface => XlsxCell::date($state, $this->style((string) AppLocale::setting('xlsx_datetime'))),
            default => $this->xlsxText($this->text($field, $record)),
        };
    }

    private function xlsxNumber(string $number, string $format): XlsxCell
    {
        return strlen(preg_replace('/\D/', '', $number) ?? '') > self::XLSX_MAX_NUMBER_DIGITS
            ? XlsxCell::text($number)
            : XlsxCell::number($number, $this->style($format));
    }

    private function style(string $format): int
    {
        return XlsxStreamWriter::styleFor($this->formats, $format);
    }

    /** Text als „inlineStr“: Ein Text mit „=“ am Anfang wird so nie zur Formel. */
    private function xlsxText(?string $value): ?XlsxCell
    {
        return $value === null || $value === '' ? null : XlsxCell::text($value);
    }

    private function csvCell(CustomField $field, DynamicRecord $record): string
    {
        $state = $record->getAttribute($field->name);

        return match (true) {
            $state === null && in_array($field->valueType(), [FieldType::Integer, FieldType::Decimal, FieldType::Date, FieldType::DateTime], true) => '',
            $field->type === FieldType::Integer => (string) $state,
            $field->valueType() === FieldType::Decimal => str_replace('.', (string) AppLocale::setting('csv_decimal'), (string) $state),
            $field->type === FieldType::Date && $state instanceof DateTimeInterface => (string) AppLocale::date($state),
            $field->type === FieldType::DateTime && $state instanceof DateTimeInterface => (string) AppLocale::dateTime($state),
            default => $this->csvText($this->text($field, $record)) ?? '',
        };
    }

    /** Excel führt CSV-Werte mit diesen Anfangszeichen als Formel aus, daher entschärfen. */
    private function csvText(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^[=+\-@\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }

    private function text(CustomField $field, DynamicRecord $record): ?string
    {
        $state = $record->getAttribute($field->name);

        $text = match ($field->valueType()) {
            FieldType::Boolean => $state === null ? null : ($state ? __('common.yes') : __('common.no')),
            FieldType::Select => Translations::pick(collect((array) ($field->optionsArray()['options'] ?? []))->firstWhere('value', $state)['label'] ?? null, (string) $state),
            FieldType::Relation => DynamicTableBuilder::relationLabel($field, $record),
            // Dateien: Namen (die Dateien selbst nicht).
            FieldType::File => ($names = RecordFiles::names($state)) === [] ? null : implode('; ', $names),
            default => $state,
        };

        return $text === null ? null : (string) $text;
    }

    private static function decimalFormat(CustomField $field): string
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
