<?php

declare(strict_types=1);

namespace App\Documents\QrBill;

use App\Documents\PdfDocument;
use App\Documents\PdfFonts;
use Sprain\SwissQrBill\PaymentPart\Output\AbstractOutput;
use Sprain\SwissQrBill\PaymentPart\Output\Element\OutputElementInterface;
use Sprain\SwissQrBill\PaymentPart\Output\Element\Placeholder;
use Sprain\SwissQrBill\PaymentPart\Output\Element\Text;
use Sprain\SwissQrBill\PaymentPart\Output\Element\Title;
use Sprain\SwissQrBill\PaymentPart\Translation\Translation;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;

/**
 * Zeichnet Empfangsschein und Zahlteil einer QR-Rechnung mit tc-lib-pdf unten auf eine A4-Seite (B-124, D4a): 105 mm
 * hoch, Empfangsschein 62 mm, Zahlteil 148 mm, Trennlinien mit Schere. Inhalt, Reihenfolge und Übersetzungen der
 * Elemente kommen aus sprain/swiss-qr-bill (AbstractOutput), Masse und Schriftgrössen aus dem Style Guide von SIX:
 * Liberation Sans, Titel 11 pt, Überschriften 6 bzw. 8 pt, Werte 8 bzw. 10 pt, QR-Code 46 × 46 mm.
 */
final class QrBillPainter extends AbstractOutput
{
    public const string FONT = 'liberationsans';

    /** Oberkante des Zahlteils auf A4 (297 − 105 mm). */
    public const float TOP = 192.0;

    private const float RECEIPT_X = 5.0;

    private const float RECEIPT_WIDTH = 52.0;

    private const float PAYMENT_X = 67.0;

    private const float INFO_X = 118.0;

    private const float INFO_WIDTH = 87.0;

    public function __construct(QrBill $qrBill, string $language, private readonly PdfDocument $pdf, private readonly int $pid)
    {
        parent::__construct($qrBill, in_array($language, ['de', 'fr', 'it', 'en'], true) ? $language : 'de');
        $this->setQrCodeImageFormat(QrCode::FILE_FORMAT_SVG);
    }

    public function getPaymentPart(): ?string
    {
        PdfFonts::boot();

        foreach (['', 'B'] as $style) {
            $this->pdf->font->insert($this->pdf->pon, self::FONT, $style, 10);
            $this->pdf->font->popLastFont();
        }

        $top = self::TOP;
        $this->separators($top);

        // Empfangsschein
        $this->html($this->title('receipt'), self::RECEIPT_X, $top + 5, self::RECEIPT_WIDTH);
        $this->elements($this->getInformationElementsOfReceipt(), self::RECEIPT_X, $top + 12, self::RECEIPT_WIDTH, true);
        $this->elements($this->getCurrencyElements(), self::RECEIPT_X, $top + 68, 12, true);
        $this->elements($this->getAmountElementsReceipt(), self::RECEIPT_X + 13, $top + 68, 39, true);
        $this->html('<div style="font-family: '.self::FONT.'; font-size: 6pt; font-weight: bold; text-align: right">'
            .e(Translation::get('acceptancePoint', $this->language)).'</div>', self::RECEIPT_X, $top + 82, self::RECEIPT_WIDTH);

        // Zahlteil
        $this->html($this->title('paymentPart'), self::PAYMENT_X, $top + 5, 46);
        $this->qrCode(self::PAYMENT_X, $top + 17);
        $this->elements($this->getCurrencyElements(), self::PAYMENT_X, $top + 68, 14, false);
        $this->elements($this->getAmountElements(), self::PAYMENT_X + 14, $top + 68, 37, false);
        $this->elements($this->getInformationElements(), self::INFO_X, $top + 5, self::INFO_WIDTH, false);

        return null;
    }

    /** Trennlinien über die Breite und zwischen Empfangsschein und Zahlteil, mit Schere. */
    private function separators(float $top): void
    {
        $style = ['lineWidth' => 0.2, 'lineCap' => 'butt', 'lineJoin' => 'miter', 'dashArray' => [], 'dashPhase' => 0, 'lineColor' => 'black', 'fillColor' => 'black'];
        $out = $this->pdf->graph->getLine(0, $top, 210, $top, $style)
            .$this->pdf->graph->getLine(62, $top, 62, 297, $style);
        $this->pdf->page->addContent($out, $this->pid);
        // Schere (Unicode ✂) auf jeder Linie (IG 3.7), aus der mitgelieferten DejaVu Sans – Liberation Sans hat das
        // Zeichen nicht. Auf der senkrechten Linie um 90° gedreht, Spitze nach unten.
        $scissors = '<div style="font-family: '.PdfFonts::DEFAULT.'; font-size: 10pt; line-height: 1">&#x2702;</div>';
        $this->html($scissors, 5, $top - 2.3, 6);
        [$cx, $cy] = [62.0, $top + 5.0];
        $this->pdf->page->addContent(
            $this->pdf->graph->getStartTransform()
            .$this->pdf->graph->getRotation(-90, $cx, $cy)
            .$this->pdf->getHTMLCell($scissors, $cx - 1.7, $cy - 2.0, 6)
            .$this->pdf->graph->getStopTransform(),
            $this->pid,
        );
    }

    private function title(string $key): string
    {
        return '<div style="font-family: '.self::FONT.'; font-size: 11pt; font-weight: bold; line-height: 1">'.e(Translation::get($key, $this->language)).'</div>';
    }

    /**
     * Überschriften und Werte untereinander; Platzhalter (Eckmarken für Betrag bzw. Zahlungspflichtigen) als Bild.
     *
     * @param  list<OutputElementInterface>  $elements
     */
    private function elements(array $elements, float $x, float $y, float $width, bool $receipt): float
    {
        $heading = $receipt ? 6 : 8;
        $value = $receipt ? 8 : 10;
        $html = '';

        foreach ($elements as $element) {
            if ($element instanceof Title) {
                $html .= '<div style="font-family: '.self::FONT.'; font-size: '.$heading.'pt; font-weight: bold; line-height: 1.25">'
                    .e(Translation::get(str_replace('text.', '', $element->getTitle()), $this->language)).'</div>';
            } elseif ($element instanceof Text) {
                $html .= '<div style="font-family: '.self::FONT.'; font-size: '.$value.'pt; line-height: 1.15">'
                    .nl2br(e(str_replace('text.', '', $element->getText())), false).'</div>'
                    .'<div style="font-size: '.($receipt ? 4 : 5).'pt; line-height: 1">&nbsp;</div>';
            } elseif ($element instanceof Placeholder) {
                $y = $html === '' ? $y : $this->html($html, $x, $y, $width) + 0.8;
                $html = '';
                $this->placeholder($element, $x, $y);
                $y += (float) $element->getHeight() + 1;
            }
        }

        return $html === '' ? $y : $this->html($html, $x, $y, $width);
    }

    private function placeholder(Placeholder $element, float $x, float $y): void
    {
        $svg = (string) file_get_contents($element->getFile(Placeholder::FILE_TYPE_SVG));
        $soid = $this->pdf->addSVG('@'.$svg, $x, $y, (float) $element->getWidth(), (float) $element->getHeight());
        $this->pdf->page->addContent("q\n".$this->pdf->getSetSVG($soid)."\nQ\n", $this->pid);
    }

    /**
     * QR-Code 46 × 46 mm als Vektor. Das Schweizerkreuz bettet die Bibliothek als PNG ins SVG ein, das tc-lib-pdf nicht
     * liest – es wird entfernt und als Vektor gezeichnet: 7 × 7 mm, weisser Rand, schwarzes Quadrat, weisses Kreuz
     * (Proportionen aus swiss-cross.optimized.png der Bibliothek, 166 px = 7 mm).
     */
    private function qrCode(float $x, float $y): void
    {
        $qrCode = $this->getQrCode();
        $qrCode->avoidCompactSvgs();
        $svg = (string) preg_replace('/<image\b[^>]*\/>|<image\b.*?<\/image>/s', '', $qrCode->getAsString(QrCode::FILE_FORMAT_SVG));
        $soid = $this->pdf->addSVG('@'.$svg, $x, $y, 46, 46);
        // Eigener Grafikzustand: Die SVG-Ausgabe lässt sonst einen Ausschnitt (Clipping) offen, der das Kreuz verdeckt.
        $this->pdf->page->addContent("q\n".$this->pdf->getSetSVG($soid)."\nQ\n", $this->pid);

        $unit = 7 / 166;
        $left = $x + 23 - 3.5;
        $top = $y + 23 - 3.5;
        $fill = static fn (string $color): array => ['all' => ['lineWidth' => 0, 'lineCap' => 'butt', 'lineJoin' => 'miter', 'dashArray' => [], 'dashPhase' => 0, 'lineColor' => $color, 'fillColor' => $color]];
        $rect = fn (float $px, float $py, float $pw, float $ph, string $color): string => $this->pdf->graph->getRect($left + $px * $unit, $top + $py * $unit, $pw * $unit, $ph * $unit, 'F', $fill($color));

        $this->pdf->page->addContent(
            $rect(0, 0, 166, 166, 'white')
            .$rect(12, 12, 142, 142, 'black')
            .$rect(68, 34, 30, 92, 'white')
            .$rect(36, 68, 94, 30, 'white'),
            $this->pid,
        );
    }

    /** HTML an fester Stelle auf der Seite; gibt die Unterkante zurück. */
    private function html(string $html, float $x, float $y, float $width): float
    {
        $this->pdf->page->addContent($this->pdf->getHTMLCell($html, $x, $y, $width), $this->pid);

        return (float) $this->pdf->getLastHTMLCellEnd()['y'];
    }
}
