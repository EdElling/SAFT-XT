<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\DocumentLetterhead;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Word-Fassung eines Dokuments (B-124, D4b) aus demselben HTML wie das PDF, zum Nachbearbeiten: Kopf mit Logo und
 * Absender auf der ersten Seite, Adressfeld an der Stelle des Briefpapiers, Inhalt mit den Klassen der Vorlage als
 * Word-Formate (Farben, Tabelle „positionen“ mit wiederholtem Kopf, Summenzeile), Seitenzahlen in der Fusszeile.
 * Ein Briefpapier als PDF-Hintergrund und die QR-Rechnung gibt es nur im PDF. Schrift: Arial (in Word überall da).
 */
final class DocxRenderer
{
    public const string FONT = 'Arial';

    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * @param  array{title?: string, lang?: string, address?: string|null}  $options  address: HTML der Empfängeradresse
     */
    public function render(string $html, Letterhead $letterhead, array $options = []): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName(self::FONT);
        $word->setDefaultFontSize(10);
        $word->setDefaultParagraphStyle(['spaceAfter' => self::twip(2.5), 'spacing' => 40]);
        $word->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language(self::language($options['lang'] ?? app()->getLocale())));
        $word->getDocInfo()->setTitle((string) ($options['title'] ?? ''))->setCreator((string) ($letterhead->sender['firma'] ?? ''))
            ->setCompany((string) ($letterhead->sender['firma'] ?? ''))->setLastModifiedBy('SAFT XT');

        $first = $letterhead->margins['first'];
        $next = $letterhead->margins['next'];
        $section = $word->addSection([
            'paperSize' => 'A4',
            'marginTop' => self::twip((float) $next['top']), 'marginBottom' => self::twip((float) $next['bottom']),
            'marginLeft' => self::twip((float) $first['left']), 'marginRight' => self::twip((float) $first['right']),
            'headerHeight' => self::twip(10), 'footerHeight' => self::twip(8),
        ]);
        $this->head($section, $letterhead);
        $this->footer($section, $letterhead);

        $top = (float) $next['top'];
        $address = $options['address'] ?? null;

        if ($address !== null && $address !== '') {
            $field = PdfRenderer::ADDRESS[$letterhead->addressPosition] ?? PdfRenderer::ADDRESS[DocumentLetterhead::RIGHT];
            $width = 210 - (float) $first['left'] - (float) $first['right'];
            $indent = max(0.0, (float) $field['left'] - (float) $first['left']);
            // Adressfeld als unsichtbare Tabelle mit fester Höhe an der Stelle des Fensterkuverts; der Inhalt folgt darunter.
            $section->addText('', ['size' => 1], ['spaceAfter' => 0, 'spaceBefore' => self::twip(max(0.0, (float) $field['top'] - $top - 1))]);
            $table = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 0, 'layout' => 'fixed']);
            $table->addRow(self::twip((float) $field['height']), ['exactHeight' => true, 'cantSplit' => true]);
            if ($indent >= 1) {
                $table->addCell(self::twip($indent));
            }

            $cell = $table->addCell(self::twip(min((float) $field['width'], $width - $indent)));
            $cell->addText(htmlspecialchars($this->pdf->senderLine($letterhead), ENT_NOQUOTES), ['size' => 7, 'color' => self::hex($letterhead->secondary)], ['spaceAfter' => self::twip(1.5)]);

            foreach (preg_split('/<br\s*\/?>/i', $address) ?: [] as $line) {
                $cell->addText(htmlspecialchars(trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5)), ENT_NOQUOTES), ['color' => self::hex($letterhead->text)], ['spaceAfter' => 0]);
            }

            $section->addText('', [], ['spaceAfter' => self::twip(PdfRenderer::ADDRESS_GAP)]);
        }

        $file = tempnam(sys_get_temp_dir(), 'docx');
        // PhpWord 1.4 löst unter PHP 8.5 bei jedem Absatz eine Deprecation aus (null als Array-Schlüssel): nur diese
        // Meldungen aus PhpWord übergehen, alles andere wie gewohnt.
        set_error_handler(static fn (int $no, string $message, string $path): bool => str_contains($path, '/phpoffice/'), E_DEPRECATED);

        try {
            Html::addHtml($section, $this->inline(PdfRenderer::sanitize($html), $letterhead), false, false);
            IOFactory::createWriter($word, 'Word2007')->save((string) $file);

            return (string) file_get_contents((string) $file);
        } finally {
            restore_error_handler();
            @unlink((string) $file);
        }
    }

    /** Kopf der ersten Seite: Logo und Absender gegenüber dem Adressfeld; Folgeseiten ohne Kopf. */
    private function head(Section $section, Letterhead $letterhead): void
    {
        $header = $section->addHeader(\PhpOffice\PhpWord\Element\Header::FIRST);
        $section->addHeader();
        $align = $letterhead->addressPosition === DocumentLetterhead::RIGHT ? Jc::START : Jc::END;
        $logo = $letterhead->logo !== null ? PdfRenderer::dataUri($letterhead->logo) : null;

        if ($logo !== null && ! str_starts_with($logo, 'data:image/svg')) {
            $data = base64_decode(substr($logo, (int) strpos($logo, ',') + 1), true);
            $ratio = PdfRenderer::aspectRatio((string) $letterhead->logo) ?? 1.0;
            $height = $letterhead->logoHeight;
            $width = min(Letterhead::LOGO_MAX_WIDTH, $height * $ratio);
            $height = $width / $ratio;

            if ($data !== false) {
                $header->addImage($data, ['height' => Converter::cmToPoint($height / 10), 'width' => Converter::cmToPoint($width / 10), 'alignment' => $align]);
            }
        }

        $lines = array_filter([$letterhead->sender['firma'] ?? null, $letterhead->sender['adresse'] ?? null, $letterhead->sender['kontakt'] ?? null]);

        foreach (preg_split('/\R/u', implode("\n", $lines)) ?: [] as $line) {
            $header->addText(htmlspecialchars($line, ENT_NOQUOTES), ['size' => 8, 'color' => self::hex($letterhead->secondary)], ['alignment' => $align, 'spaceAfter' => 0]);
        }
    }

    /** Fusszeile „Seite 1 / 2“ wie im PDF, auf allen Seiten. */
    private function footer(Section $section, Letterhead $letterhead): void
    {
        foreach ([\PhpOffice\PhpWord\Element\Footer::FIRST, \PhpOffice\PhpWord\Element\Footer::AUTO] as $type) {
            $section->addFooter($type)->addPreserveText(__('documents.page').' {PAGE} / {NUMPAGES}', ['size' => 8, 'color' => self::hex($letterhead->secondary)], ['alignment' => Jc::END]);
        }
    }

    /**
     * Klassen der Vorlage als Inline-Stile, die PhpWord versteht (es liest nur einzelne Klassen, keine Kombinationen
     * oder Nachfahren wie „table.positionen th“).
     */
    public function inline(string $html, Letterhead $letterhead): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="saft-root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $classes = static fn (DOMElement $el): array => preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
        $has = static fn (DOMElement $el, string $class): bool => in_array($class, $classes($el), true);
        $closest = static function (DOMElement $el, string $tag): ?DOMElement {
            for ($node = $el->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
                if (strtolower($node->nodeName) === $tag) {
                    return $node;
                }
            }

            return null;
        };
        [$primary, $secondary, $text] = [$letterhead->primary, $letterhead->secondary, $letterhead->text];
        $sizes = ['h1' => 16, 'h2' => 13, 'h3' => 11];

        foreach ($xpath->query('//*') ?: [] as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($el->nodeName);
            $style = [];

            if (isset($sizes[$tag])) {
                $style += ['color' => $primary, 'font-size' => $sizes[$tag].'pt', 'font-weight' => 'bold', 'margin-bottom' => '3mm'];
            }

            if (in_array($tag, ['td', 'th'], true) && ($table = $closest($el, 'table')) !== null && $has($table, 'positionen')) {
                $row = $closest($el, 'tr');
                $style += ['padding' => '3pt 4pt'];
                $style += $tag === 'th'
                    ? ['background-color' => $primary, 'color' => '#ffffff', 'font-weight' => 'bold']
                    : ($row !== null && $has($row, 'summe')
                        ? ['border-top' => '1.5pt '.$text.' solid', 'font-weight' => 'bold']
                        : ['border-bottom' => '1pt '.$secondary.' solid']);
            }

            // Absätze in Zellen ohne Abstand danach (sonst würden die Zeilen hoch).
            if (in_array($tag, ['td', 'th'], true)) {
                $style += ['margin-bottom' => '0pt'];
            }

            foreach ($classes($el) as $class) {
                $style += match ($class) {
                    'primaer' => ['color' => $primary],
                    'sekundaer' => ['color' => $secondary],
                    'klein' => ['font-size' => '8pt'],
                    'rechts' => ['text-align' => 'right'],
                    'fett' => ['font-weight' => 'bold'],
                    default => [],
                };
            }

            if ($tag === 'table' && $has($el, 'positionen')) {
                $el->setAttribute('width', '100%');
            }

            if ($style !== []) {
                $own = trim($el->getAttribute('style'));
                $el->setAttribute('style', implode('; ', array_map(static fn (string $k, string $v): string => $k.': '.$v, array_keys($style), $style)).($own !== '' ? '; '.$own : ''));
            }

        }

        // Klassen erst jetzt entfernen: Zellen fragen oben nach der Klasse ihrer Tabelle bzw. Zeile.
        foreach ($xpath->query('//*[@class]') ?: [] as $el) {
            if ($el instanceof DOMElement) {
                $el->removeAttribute('class');
            }
        }

        $root = $dom->getElementById('saft-root');
        $out = '';

        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $dom->saveXML($child);
        }

        return $out;
    }

    private static function twip(float $mm): int
    {
        return (int) round(Converter::cmToTwip($mm / 10));
    }

    private static function hex(string $color): string
    {
        return ltrim($color, '#');
    }

    private static function language(string $locale): string
    {
        return ['de' => 'de-CH', 'fr' => 'fr-CH', 'it' => 'it-CH', 'en' => 'en-GB'][$locale] ?? 'de-CH';
    }
}
