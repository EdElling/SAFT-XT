<?php

declare(strict_types=1);

namespace App\Reports\Export;

use App\DynamicData\Export\ExportFormat;
use App\DynamicData\Schema\FieldType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\ValueFormatter;
use App\Support\AppLocale;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Export einer Auswertung als Excel oder CSV – so, wie die Ergebnisseite sie zeigt: Liste bzw.
 * gruppierte Tabelle oder (bei Kreuztabelle und Diagrammen mit Spaltenfeld) die Kreuztabelle.
 * Excel: Zahlen und Datumswerte als solche; CSV: Trennzeichen und Dezimalzeichen der Sprache
 * (wie beim Datenexport), Werte mit Formel-Anfangszeichen entschärft.
 */
final class ReportExporter
{
    private const int XLSX_MAX_CELL_LENGTH = 32767;

    /** @var array<string, Style> */
    private array $styles = [];

    public function __construct(private readonly ValueFormatter $formatter) {}

    /** @return string Pfad der temporären Datei; Aufräumen liegt beim Aufrufer */
    public function write(ReportResult $result, ExportFormat $format, string $title): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-report-');
        $writer = $format === ExportFormat::Xlsx ? new XlsxWriter(new XlsxOptions) : $this->csvWriter();

        try {
            $writer->openToFile($path);

            if ($writer instanceof XlsxWriter) {
                $writer->getCurrentSheet()->setName($this->sheetName($title));
            }

            foreach ($this->grid($result) as $i => $cells) {
                $writer->addRow(new Row(
                    array_map(fn (array $cell): Cell => $this->cell($writer, $cell[0], $cell[1]), $cells),
                    $i === 0 ? (new Style)->setFontBold() : null,
                ));
            }
        } catch (Throwable $e) {
            $writer->close();
            @unlink($path);

            throw $e;
        }

        $writer->close();

        return $path;
    }

    /**
     * Zeilen aus Zellen [Wert, Spalte|null]; Zeile 0 ist die Kopfzeile (Spalte null = Text).
     *
     * @return list<list<array{0: mixed, 1: ReportColumn|null}>>
     */
    private function grid(ReportResult $result): array
    {
        $cross = ($result->report->definition->visual['type'] ?? 'table') === 'table' ? null : $result->crossTab();

        if ($cross === null) {
            return [
                array_map(fn (ReportColumn $c): array => [$c->label, null], $result->columns),
                ...array_map(fn (array $row): array => array_map(fn (ReportColumn $c): array => [$row[$c->key], $c], $result->columns), $result->rows),
            ];
        }

        $header = array_map(fn (ReportColumn $c): array => [$c->label, null], $cross->rowColumns);

        foreach ($cross->columnValues as $value) {
            foreach ($cross->measures as $measure) {
                $label = $this->formatter->format($value, $cross->column) ?: __('reports.ui.empty_value');
                $header[] = [count($cross->measures) > 1 ? $label.' · '.$measure->label : $label, null];
            }
        }

        $rows = [$header];

        foreach ($cross->rows as $row) {
            $cells = array_map(fn (ReportColumn $c, int $i): array => [$row['keys'][$i], $c], $cross->rowColumns, array_keys($cross->rowColumns));

            foreach ($cross->columnValues as $value) {
                foreach ($cross->measures as $measure) {
                    $cells[] = [$cross->cell($row, $value, $measure->key), $measure];
                }
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    private function cell(WriterInterface $writer, mixed $value, ?ReportColumn $column): Cell
    {
        if ($value === null || $value === '') {
            return new Cell\EmptyCell(null, null);
        }

        if ($column === null) {
            return $this->text($writer, (string) $value);
        }

        $temporal = in_array($column->type(), [FieldType::Date, FieldType::DateTime], true) && ($column->field?->bucket === null || $column->aggregate !== null);

        if ($writer instanceof XlsxWriter) {
            return match (true) {
                $column->isNumeric() && is_numeric($value) => new Cell\NumericCell($value + 0, $this->style($this->numberFormat($column))),
                $temporal => new Cell\DateTimeCell(CarbonImmutable::parse((string) $value), $this->style(AppLocale::setting($column->type() === FieldType::Date ? 'xlsx_date' : 'xlsx_datetime'))),
                default => $this->text($writer, $this->formatter->format($value, $column)),
            };
        }

        return $column->isNumeric() && is_numeric($value)
            ? new Cell\StringCell(str_replace('.', AppLocale::setting('csv_decimal'), number_format($value + 0, $this->formatter->decimals($column), '.', '')), null)
            : $this->text($writer, $this->formatter->format($value, $column));
    }

    /** Text als Text: Excel würde „=…“ sonst als Formel lesen, CSV entschärft wie beim Datenexport. */
    private function text(WriterInterface $writer, string $value): Cell
    {
        if ($writer instanceof XlsxWriter) {
            return new Cell\StringCell(mb_substr($value, 0, self::XLSX_MAX_CELL_LENGTH), null);
        }

        return new Cell\StringCell(preg_match('/^[=+\-@\t\r\n]/', $value) === 1 ? "'".$value : $value, null);
    }

    private function numberFormat(ReportColumn $column): string
    {
        $decimals = $column->type() === FieldType::Integer ? 0 : $this->formatter->decimals($column);

        if (! ValueFormatter::groupsDigits($column)) {
            return '0';
        }

        return $decimals > 0 ? '#,##0.'.str_repeat('0', $decimals) : '#,##0';
    }

    private function style(string $format): Style
    {
        return $this->styles[$format] ??= (new Style)->setFormat($format);
    }

    private function csvWriter(): CsvWriter
    {
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = AppLocale::setting('csv_delimiter');
        $options->SHOULD_ADD_BOM = true;

        return new CsvWriter($options);
    }

    /** Excel erlaubt 31 Zeichen und einige Sonderzeichen nicht als Blattname. */
    private function sheetName(string $title): string
    {
        $name = trim(mb_substr((string) preg_replace('/[\\\\\/?*:\[\]]/u', ' ', $title), 0, 31), " '");

        return $name === '' ? 'Export' : $name;
    }
}
