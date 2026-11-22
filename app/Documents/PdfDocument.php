<?php

declare(strict_types=1);

namespace App\Documents;

use Com\Tecnick\Pdf\Import\PageTemplateInterface;
use Com\Tecnick\Pdf\Page\Settings;
use Com\Tecnick\Pdf\Tcpdf;

/**
 * tc-lib-pdf-Dokument mit dem Seiteninhalt, der auf jeder Seite steht: Briefpapier als Hintergrund, Kopf (nur Seite 1,
 * nur ohne Briefpapier-PDF) und Fusszeile mit „Seite x / y“. tc-lib-pdf ruft defaultPageContent() für jede neue
 * Seite auf, auch beim automatischen Umbruch.
 */
final class PdfDocument extends Tcpdf
{
    /** Hintergrund der ersten Seite und der Folgeseiten. */
    public ?PageTemplateInterface $firstBackground = null;

    public ?PageTemplateInterface $nextBackground = null;

    /** HTML des Kopfs auf Seite 1 (leer: kein Kopf). */
    public string $headHtml = '';

    /** Unterkante des Kopfs auf Seite 1 (mm), gemessen beim Setzen; 0 ohne Kopf. */
    public float $headBottom = 0.0;

    public string $footerLabel = 'Seite';

    public string $footerColor = '#6b7280';

    public string $fontFamily = PdfFonts::DEFAULT;

    public function defaultPageContent(int $pid = -1): string
    {
        $pid = $pid < 0 ? (int) $this->page->getPageId() : $pid;
        $page = $this->page->getPage($pid);
        $left = (float) $page['margin']['PL'];
        $width = (float) $page['width'] - $left - (float) $page['margin']['PR'];
        $background = $pid === 0 ? $this->firstBackground : $this->nextBackground;

        if ($background !== null) {
            $this->useImportedPage($background, 0, 0, (float) $page['width'], (float) $page['height']);
        }

        $out = '';

        if ($pid === 0 && $this->headHtml !== '') {
            $out .= $this->getHTMLCell($this->headHtml, $left, PdfRenderer::HEAD_TOP, $width);
            $this->headBottom = (float) $this->getLastHTMLCellEnd()['y'];
        }

        $font = $this->font->insert($this->pon, $this->fontFamily, '', 8);
        $out .= $this->graph->getStartTransform().$font['out'].$this->color->getPdfFillColor($this->footerColor);
        $out .= $this->getTextCell($this->footerLabel.' '.Settings::PAGE_NUM.' / '.Settings::PAGE_TOT, $left, (float) $page['height'] - 13, $width, 0, 0, 0, 'T', 'R');
        $out .= $this->graph->getStopTransform();
        $this->font->popLastFont();

        return $out;
    }
}
