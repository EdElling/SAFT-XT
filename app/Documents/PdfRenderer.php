<?php

declare(strict_types=1);

namespace App\Documents;

use App\Documents\QrBill\QrBillFactory;
use App\Documents\QrBill\QrBillPainter;
use App\Models\DocumentLetterhead;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Support\Facades\File;
use Sprain\SwissQrBill\QrBill;
use Throwable;

/**
 * Setzt HTML auf das Briefpapier und erzeugt das PDF mit tc-lib-pdf (reines PHP, LGPL, läuft auch auf einfachem
 * Webspace).
 *
 * - Briefpapier-PDF als Hintergrund: Seite 1 der ersten Datei auf der ersten Seite, die Folgeseiten aus der zweiten
 *   Datei bzw. Seite 2 der ersten; ohne Briefpapier ein einfacher Kopf mit Logo und Absender.
 * - Ränder je Seitentyp: Seite 1 und eine leere Vorlage für die Folgeseiten werden vorab angelegt; der automatische
 *   Umbruch springt auf die Vorlage und übernimmt ihre Ränder für alle weiteren Seiten.
 * - Adressfeld als eigene Zelle an fester Stelle (Fenstercouvert), der Inhalt beginnt darunter.
 * - Hausschrift und Hausfarben als feste Werte im Grund-CSS; PDF/A-3b, wenn eingestellt.
 * - Keine Inhalte von aussen: Bilder nur als data:-Adresse, keine Hosts, Dateien nur aus den eigenen Verzeichnissen.
 */
final class PdfRenderer
{
    /** Lage des Adressfelds (mm ab linkem bzw. oberem Blattrand): DIN 5008 Form B links, Schweiz rechts. */
    public const array ADDRESS = [
        DocumentLetterhead::LEFT => ['left' => 20, 'top' => 45, 'width' => 85, 'height' => 45],
        DocumentLetterhead::RIGHT => ['left' => 118, 'top' => 50, 'width' => 75, 'height' => 45],
    ];

    /** Mindestabstand zwischen Inhalt und Zahlteil der QR-Rechnung auf derselben Seite (mm). */
    private const float QR_GAP = 5.0;

    /** Abstand zwischen Adressfeld und Inhalt (mm). */
    private const float ADDRESS_GAP = 8.0;

    /**
     * @param  string  $html  Inhalt (Body) des Dokuments
     * @param  array{title?: string, author?: string, lang?: string, css?: string, address?: string|null, qr?: QrBill|null, qr_position?: string}  $options  address: HTML der Empfängeradresse; mit ihr steht das Adressfeld auf Seite 1. qr: QR-Rechnung, deren Zahlteil auf eine eigene letzte Seite (qr_position page) bzw. unten auf die letzte Seite kommt (bottom, wenn Platz ist)
     */
    public function render(string $html, Letterhead $letterhead, array $options = []): string
    {
        PdfFonts::boot();
        $house = PdfFonts::house($letterhead->fonts);
        $family = $house !== null ? PdfFonts::HOUSE : PdfFonts::DEFAULT;
        $pdf = $this->document($letterhead, $house);
        $pdf->fontFamily = $family;
        $pdf->footerLabel = __('documents.page');
        $pdf->footerColor = $letterhead->secondary;
        $pdf->setTitle($options['title'] ?? '');
        $pdf->setAuthor($options['author'] ?? (string) ($letterhead->sender['firma'] ?? ''));
        $pdf->setCreator('SAFT XT');
        $pdf->setLanguage($options['lang'] ?? app()->getLocale());

        if ($house !== null) {
            foreach (['', 'B', 'I', 'BI'] as $style) {
                $pdf->font->insert($pdf->pon, PdfFonts::HOUSE, $style, 10, null, null, $house.'/'.PdfFonts::HOUSE.strtolower($style).'.json');
                $pdf->font->popLastFont();
            }
        }

        $this->backgrounds($pdf, $letterhead);
        $pdf->headHtml = $letterhead->firstPage === null ? $this->head($letterhead) : '';
        $pdf->font->insert($pdf->pon, $family, '', 10);

        $first = $letterhead->margins['first'];
        $pdf->enableDefaultPageContent(true);
        $pdf->addPage(self::page($first));
        $pdf->enableDefaultPageContent(false);
        $pdf->addPage(self::page($letterhead->margins['next']));
        $template = count($pdf->page->getPage(1)['content']);
        $pdf->enableDefaultPageContent(true);
        $pdf->setCurrentPage(0);

        $style = '<style>'.$this->css($letterhead, $family)."\n".self::sanitizeCss((string) ($options['css'] ?? '')).'</style>';
        // Der Inhalt beginnt unter dem Kopf, auch wenn Logo und Absender über den oberen Rand hinausreichen.
        $top = max((float) $first['top'], $pdf->headBottom > 0 ? $pdf->headBottom + self::HEAD_GAP : 0.0);
        $address = $options['address'] ?? null;

        if ($address !== null && $address !== '') {
            $field = self::ADDRESS[$letterhead->addressPosition] ?? self::ADDRESS[DocumentLetterhead::RIGHT];
            $pdf->addHTMLCell($style.'<div class="absenderzeile">'.e($this->senderLine($letterhead)).'</div>'.self::sanitize($address), $field['left'], $field['top'], $field['width']);
            $pdf->setCurrentPage(0);
            $top = max($top, $field['top'] + $field['height'] + self::ADDRESS_GAP);
        }

        $pdf->addHTMLCell($style.self::sanitize($html), (float) $first['left'], $top, 210 - (float) $first['left'] - (float) $first['right']);

        // Die Vorlage für die Folgeseiten wurde nicht gebraucht: wieder entfernen.
        if (count($pdf->page->getPages()) === 2 && count($pdf->page->getPage(1)['content']) === $template) {
            $pdf->page->delete(1);
        }

        if (($options['qr'] ?? null) instanceof QrBill) {
            $this->paymentPart($pdf, $options['qr'], (string) ($options['qr_position'] ?? QrBillFactory::PAGE), (string) ($options['lang'] ?? app()->getLocale()));
        }

        return $pdf->getOutPDFString();
    }

    /**
     * Zahlteil der QR-Rechnung: unten auf der letzten Seite, wenn der Inhalt darüber endet (bottom; Hintergrund und
     * Fusszeile dort werden weiss überdeckt), sonst auf einer eigenen Seite ohne Briefpapier und Fusszeile.
     */
    private function paymentPart(PdfDocument $pdf, QrBill $qrBill, string $position, string $language): void
    {
        $end = $pdf->getLastHTMLCellEnd();
        $last = array_key_last($pdf->page->getPages());

        if ($position === QrBillFactory::BOTTOM && (int) $end['page'] === $last && (float) $end['y'] <= QrBillPainter::TOP - self::QR_GAP) {
            $white = ['lineWidth' => 0, 'lineCap' => 'butt', 'lineJoin' => 'miter', 'dashArray' => [], 'dashPhase' => 0, 'lineColor' => 'white', 'fillColor' => 'white'];
            $pdf->page->addContent($pdf->graph->getRect(0, QrBillPainter::TOP, 210, 297 - QrBillPainter::TOP, 'F', ['all' => $white]), (int) $last);
            (new QrBillPainter($qrBill, $language, $pdf, (int) $last))->getPaymentPart();

            return;
        }

        $pdf->bare = true;
        $pdf->addPage(self::page(['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]));
        $pdf->bare = false;
        (new QrBillPainter($qrBill, $language, $pdf, (int) array_key_last($pdf->page->getPages())))->getPaymentPart();
    }

    private function document(Letterhead $letterhead, ?string $house): PdfDocument
    {
        $paths = array_values(array_filter([
            PdfFonts::directory(),
            $house,
            ...array_map(fn (?string $file): ?string => $file !== null ? dirname($file) : null, [$letterhead->firstPage, $letterhead->nextPages]),
        ]));

        return new PdfDocument(
            unit: 'mm',
            isunicode: true,
            subsetfont: true,
            compress: true,
            mode: $letterhead->pdfa ? 'pdfa3b' : '',
            fileOptions: ['allowedHosts' => [], 'allowedPaths' => $paths, 'markupAllowedPaths' => [PdfFonts::directory()]],
        );
    }

    /**
     * Seitendaten für addPage(): A4 hoch mit den Rändern des Seitentyps.
     *
     * @param  array{top: float, right: float, bottom: float, left: float}  $margins
     * @return array<string, mixed>
     */
    private static function page(array $margins): array
    {
        return ['format' => 'A4', 'orientation' => 'P', 'margin' => [
            'PL' => (float) $margins['left'], 'PR' => (float) $margins['right'],
            'PT' => (float) $margins['top'], 'HB' => (float) $margins['top'], 'CT' => (float) $margins['top'],
            'PB' => (float) $margins['bottom'], 'FT' => (float) $margins['bottom'], 'CB' => (float) $margins['bottom'],
        ]];
    }

    /** Hintergrund aus dem Briefpapier: Seite 1 der ersten Datei, Folgeseiten aus der zweiten bzw. Seite 2 der ersten. */
    private function backgrounds(PdfDocument $pdf, Letterhead $letterhead): void
    {
        if ($letterhead->firstPage !== null) {
            $source = $pdf->setImportSourceData((string) file_get_contents($letterhead->firstPage));
            $pdf->firstBackground = $pdf->importPage($source, 1);

            if ($letterhead->nextPages === null && $pdf->getSourcePageCount($source) > 1) {
                $pdf->nextBackground = $pdf->importPage($source, 2);
            }
        }

        if ($letterhead->nextPages !== null) {
            $pdf->nextBackground = $pdf->importPage($pdf->setImportSourceData((string) file_get_contents($letterhead->nextPages)), 1);
        }
    }

    /** Anzahl Seiten eines PDFs; 0, wenn tc-lib-pdf es nicht lesen kann. */
    public static function pageCount(string $file): int
    {
        try {
            $pdf = new Tcpdf;

            return $pdf->getSourcePageCount($pdf->setImportSourceData((string) file_get_contents($file)));
        } catch (Throwable) {
            return 0;
        }
    }

    /** Grund-CSS: Hausschrift und -farben. Vorlagen verwenden die Klassen, nicht eigene Werte. */
    public function css(Letterhead $letterhead, string $family = PdfFonts::DEFAULT): string
    {
        return <<<CSS
            body, div, p, td, th, li { font-family: {$family}; font-size: 10pt; color: {$letterhead->text}; line-height: 1.35; }
            h1, h2, h3 { font-family: {$family}; color: {$letterhead->primary}; font-weight: bold; margin: 0 0 3mm 0; }
            h1 { font-size: 16pt; } h2 { font-size: 13pt; } h3 { font-size: 11pt; }
            .primaer { color: {$letterhead->primary}; } .sekundaer { color: {$letterhead->secondary}; } .klein { font-size: 8pt; }
            .rechts { text-align: right; } .fett { font-weight: bold; }
            table { border-collapse: collapse; }
            table.positionen { width: 100%; }
            table.positionen th { background-color: {$letterhead->primary}; color: #ffffff; text-align: left; padding: 1.5mm 2mm; font-weight: bold; }
            table.positionen td { padding: 1.5mm 2mm; border-bottom: 0.2mm solid {$letterhead->secondary}; }
            table.positionen th.rechts, table.positionen td.rechts { text-align: right; }
            table.positionen tr.summe td { border-bottom: 0; border-top: 0.4mm solid {$letterhead->text}; font-weight: bold; }
            .absenderzeile { font-size: 7pt; color: {$letterhead->secondary}; border-bottom: 0.2mm solid {$letterhead->secondary}; margin-bottom: 1.5mm; }
            .saft-kopf { font-size: 8pt; color: {$letterhead->secondary}; }
            CSS;
    }

    /** Absenderzeile über der Adresse: eingestellt oder aus Firma und Adresse. */
    public function senderLine(Letterhead $letterhead): string
    {
        return $letterhead->senderLine
            ?? implode(' · ', array_filter([$letterhead->sender['firma'], str_replace("\n", ', ', (string) $letterhead->sender['adresse'])]));
    }

    /**
     * Kopf ohne Briefpapier-PDF: Logo und Absender auf der Seite gegenüber dem Adressfeld – Adressfeld rechts
     * (Schweiz): oben links; Adressfeld links (DIN 5008): oben rechts. So überdeckt der Kopf das Adressfeld nie.
     */
    public function head(Letterhead $letterhead): string
    {
        $logo = $letterhead->logo !== null ? self::dataUri($letterhead->logo) : null;
        $lines = array_filter([$letterhead->sender['firma'], $letterhead->sender['adresse'], $letterhead->sender['kontakt']]);

        if ($logo === null && $lines === []) {
            return '';
        }

        $align = $letterhead->addressPosition === DocumentLetterhead::RIGHT ? 'left' : 'right';

        return '<style>'.$this->css($letterhead).'</style><div class="saft-kopf" style="text-align: '.$align.'">'
            .($logo !== null ? '<img src="'.$logo.'" '.$this->logoSize((string) $letterhead->logo, $letterhead->logoHeight).'></div><div class="saft-kopf" style="text-align: '.$align.'; margin-top: 4mm">' : '')
            .nl2br(e(implode("\n", $lines))).'</div>';
    }

    /**
     * Grösse des Logos als img-Attribute: eingestellte Höhe, Breite nach Seitenverhältnis; ist es dann breiter als
     * Letterhead::LOGO_MAX_WIDTH, wird es auf diese Breite verkleinert.
     */
    public function logoSize(string $file, float $height): string
    {
        $ratio = self::aspectRatio($file);
        $width = $ratio !== null ? $height * $ratio : null;

        if ($width !== null && $width > Letterhead::LOGO_MAX_WIDTH) {
            [$width, $height] = [Letterhead::LOGO_MAX_WIDTH, Letterhead::LOGO_MAX_WIDTH / $ratio];
        }

        return ($width !== null ? sprintf('width="%.1Fmm" ', $width) : '').sprintf('height="%.1Fmm"', $height);
    }

    /** Oberkante des Kopfs auf Seite 1 (mm). */
    public const float HEAD_TOP = 10.0;

    /** Abstand zwischen Kopf und Inhalt (mm). */
    public const float HEAD_GAP = 6.0;

    /** Breite / Höhe des Bilds; beim SVG aus width/height oder viewBox. */
    public static function aspectRatio(string $file): ?float
    {
        if (self::isSvg($file)) {
            $svg = SvgSanitizer::sanitize((string) @file_get_contents($file));

            if ($svg === null || ($xml = @simplexml_load_string($svg)) === false) {
                return null;
            }

            $width = (float) ($xml['width'] ?? 0);
            $height = (float) ($xml['height'] ?? 0);

            if (($width <= 0 || $height <= 0 || str_contains((string) $xml['width'], '%')) && isset($xml['viewBox'])) {
                $box = preg_split('/[\s,]+/', trim((string) $xml['viewBox'])) ?: [];
                [$width, $height] = [(float) ($box[2] ?? 0), (float) ($box[3] ?? 0)];
            }

            return $width > 0 && $height > 0 ? $width / $height : null;
        }

        $size = @getimagesize($file);

        return $size !== false && $size[0] > 0 && $size[1] > 0 ? $size[0] / $size[1] : null;
    }

    /** Grösste Breite eines eingebetteten Logos in Pixeln: Letterhead::LOGO_MAX_WIDTH (80 mm) bei 300 dpi. */
    public const int LOGO_WIDTH = 1000;

    /**
     * Bild als data:-Adresse: SVG bereinigt, PNG, JPEG und GIF auf LOGO_WIDTH verkleinert (mit gd, zwischengespeichert
     * unter storage/framework/cache/pdf-logos). Ohne gd unverändert – PNG mit Transparenz und GIF kann tc-lib-pdf
     * dann nicht einbetten.
     */
    public static function dataUri(string $file): ?string
    {
        if (! is_file($file)) {
            return null;
        }

        if (self::isSvg($file)) {
            $svg = SvgSanitizer::sanitize((string) file_get_contents($file));

            return $svg !== null ? 'data:image/svg+xml;base64,'.base64_encode($svg) : null;
        }

        $mime = (string) mime_content_type($file);

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        [$mime, $content] = self::scaled($file, $mime) ?? [$mime, (string) file_get_contents($file)];

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }

    public static function isSvg(string $file): bool
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg';
    }

    /** Braucht das Logo gd (PNG und GIF), und fehlt es? */
    public static function logoNeedsGd(string $file): bool
    {
        return ! extension_loaded('gd') && is_file($file) && in_array(mime_content_type($file), ['image/png', 'image/gif'], true);
    }

    /**
     * Verkleinertes Bild (JPEG bleibt JPEG, sonst PNG mit Transparenz); null ohne gd oder wenn es schon klein genug ist.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function scaled(string $file, string $mime): ?array
    {
        $size = @getimagesize($file);

        if (! extension_loaded('gd') || $size === false || ($size[0] <= self::LOGO_WIDTH && $mime !== 'image/gif')) {
            return null;
        }

        $target = $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png';
        $cache = storage_path('framework/cache/pdf-logos/'.md5($file.'@'.filemtime($file).'@'.self::LOGO_WIDTH).($target === 'image/jpeg' ? '.jpg' : '.png'));

        if (! is_file($cache)) {
            $source = @imagecreatefromstring((string) file_get_contents($file));

            if ($source === false) {
                return null;
            }

            $width = min(self::LOGO_WIDTH, $size[0]);
            $height = max(1, (int) round($size[1] * $width / $size[0]));
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
            File::ensureDirectoryExists(dirname($cache));
            $target === 'image/jpeg' ? imagejpeg($image, $cache, 90) : imagepng($image, $cache, 9);
        }

        return [$target, (string) file_get_contents($cache)];
    }

    /** Nichts von aussen laden: Skripte, Links auf Stylesheets, @import, src und url() ausser data:image entfernen. */
    public static function sanitize(string $html): string
    {
        $html = (string) preg_replace('#<(script|iframe|object|embed|link|meta|base|tcpdf)\b[^>]*>(.*?</\1>)?#is', '', $html);
        $html = (string) preg_replace('/\s(src|background|xlink:href)\s*=\s*("(?!data:image\/)[^"]*"|\'(?!data:image\/)[^\']*\'|(?!["\']?data:image\/)[^\s>]+)/i', '', $html);

        return self::sanitizeCss($html);
    }

    public static function sanitizeCss(string $css): string
    {
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);

        return (string) preg_replace('/url\(\s*(?![\'"]?data:image\/)[^)]*\)/i', 'none', $css);
    }
}
