<?php

declare(strict_types=1);

namespace App\DynamicData\Export;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

/**
 * Anhängbarer Excel-Schreiber (Fundament 2, F3b): Zeilen werden als XML-Fragment an eine Teildatei gehängt – aus
 * beliebig vielen Prozessen nacheinander, wie es die Schritte einer Arbeit brauchen – und erst pack() baut daraus das
 * XLSX-Paket (ZIP mit Workbook, Blatt und Stilen). Texte stehen als „inlineStr“ in der Zelle: Eine Zelle wird so nie zur
 * Formel, und es braucht keine Tabelle gemeinsamer Zeichenketten im Speicher.
 *
 * Stile: 0 Standard, 1 fett (Überschrift), ab 2 je ein Zahlen- oder Datumsformat aus $formats (Reihenfolge = Index).
 */
final class XlsxStreamWriter
{
    public const int STYLE_DEFAULT = 0;

    public const int STYLE_BOLD = 1;

    /** Mehr Zeichen fasst eine Excel-Zelle nicht. */
    public const int MAX_CELL_LENGTH = 32767;

    /** Nummern eigener Formate beginnen in Excel bei 164. */
    private const int FIRST_CUSTOM_FORMAT = 164;

    private const string NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Sekunden zwischen Excels Tag 0 (30.12.1899) und 1.1.1970. */
    private const int EXCEL_EPOCH_OFFSET = 25569 * 86400;

    /**
     * @param  string  $part  Teildatei mit den Zeilen (wird angelegt, wenn sie fehlt)
     * @param  list<string>  $formats  Zahlen- und Datumsformate (Excel-Formatcodes)
     */
    public function __construct(private readonly string $part, private readonly array $formats) {}

    /** Stil-Index eines Formats aus $formats. */
    public static function styleFor(array $formats, string $format): int
    {
        $index = array_search($format, $formats, true);

        return $index === false ? self::STYLE_DEFAULT : $index + 2;
    }

    /**
     * Eine Zeile anhängen; null lässt die Zelle leer.
     *
     * @param  list<XlsxCell|null>  $cells
     */
    public function appendRow(int $row, array $cells): void
    {
        $xml = '<row r="'.$row.'">';

        foreach ($cells as $index => $cell) {
            if ($cell === null) {
                continue;
            }

            $reference = self::column($index).$row;
            $style = $cell->style > 0 ? ' s="'.$cell->style.'"' : '';

            $xml .= match ($cell->kind) {
                XlsxCell::NUMBER => '<c r="'.$reference.'"'.$style.'><v>'.self::escape((string) $cell->value).'</v></c>',
                XlsxCell::DATE => '<c r="'.$reference.'"'.$style.'><v>'.self::serial($cell->value).'</v></c>',
                default => '<c r="'.$reference.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::escape(mb_substr((string) $cell->value, 0, self::MAX_CELL_LENGTH)).'</t></is></c>',
            };
        }

        if (file_put_contents($this->part, $xml.'</row>', FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Teildatei des Exports nicht beschreibbar.');
        }
    }

    /** Das Paket bauen und die Teildateien entfernen. */
    public function pack(string $target, string $sheetName, int $columns): void
    {
        $sheet = $this->part.'.xml';
        $this->writeSheet($sheet, $columns);

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Excel-Datei nicht möglich.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRelationships());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationships());
        $zip->addFromString('xl/styles.xml', self::styles($this->formats));
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');

        if (! $zip->close()) {
            throw new RuntimeException('Excel-Datei nicht möglich.');
        }

        @unlink($sheet);
        @unlink($this->part);
    }

    /** Blatt aus Kopf, Teildatei und Fuss zusammensetzen, ohne die Zeilen in den Speicher zu laden. */
    private function writeSheet(string $sheet, int $columns): void
    {
        $out = fopen($sheet, 'w');

        if ($out === false) {
            throw new RuntimeException('Blatt des Exports nicht beschreibbar.');
        }

        try {
            fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="'.self::NS.'">');

            // Ohne Breite zeigt Excel Datumswerte als „#####“.
            if ($columns > 0) {
                fwrite($out, '<cols><col min="1" max="'.$columns.'" width="20" customWidth="1"/></cols>');
            }

            fwrite($out, '<sheetData>');

            if (is_file($this->part)) {
                $in = fopen($this->part, 'r');

                if ($in === false) {
                    throw new RuntimeException('Teildatei des Exports nicht lesbar.');
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fwrite($out, '</sheetData></worksheet>');
        } finally {
            fclose($out);
        }
    }

    /** Spaltenbuchstaben: 0 → A, 25 → Z, 26 → AA. */
    public static function column(int $index): string
    {
        $letters = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /** Excels fortlaufende Tageszahl (1900-System), Zeitzone des Werts unverändert. */
    public static function serial(DateTimeInterface $value): string
    {
        $utc = new DateTimeImmutable($value->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));

        return rtrim(rtrim(sprintf('%.10F', ($utc->getTimestamp() + self::EXCEL_EPOCH_OFFSET) / 86400), '0'), '.');
    }

    /** XML-Maskierung; Steuerzeichen, die XML nicht erlaubt, fallen weg. */
    public static function escape(string $value): string
    {
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::NS_RELATIONSHIPS.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<workbook xmlns="'.self::NS.'" xmlns:r="'.self::NS_RELATIONSHIPS.'">'
            .'<sheets><sheet name="'.self::escape($sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::NS_RELATIONSHIPS.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::NS_RELATIONSHIPS.'/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** @param  list<string>  $formats */
    private static function styles(array $formats): string
    {
        $numFmts = '';
        $xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>';

        foreach ($formats as $index => $format) {
            $id = self::FIRST_CUSTOM_FORMAT + $index;
            $numFmts .= '<numFmt numFmtId="'.$id.'" formatCode="'.self::escape($format).'"/>';
            $xfs .= '<xf numFmtId="'.$id.'" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="'.self::NS.'">'
            .($formats === [] ? '' : '<numFmts count="'.count($formats).'">'.$numFmts.'</numFmts>')
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.(2 + count($formats)).'">'.$xfs.'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
