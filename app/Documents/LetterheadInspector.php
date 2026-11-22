<?php

declare(strict_types=1);

namespace App\Documents;

use Com\Tecnick\Pdf\Tcpdf;
use Throwable;

/**
 * Prüft ein hochgeladenes Briefpapier-PDF: lesbar (tc-lib-pdf), Seitenzahl, A4 und – für PDF/A – Transparenzen.
 * Die Prüfung auf Transparenz ist eine Schätzung: Sie sucht in den (entpackten) Objekten nach weicher Maske, Deckkraft
 * unter 1 und Füllmethoden (Transparenzgruppen allein nicht – PDF-Programme setzen sie oft auf jeder Seite).
 */
final class LetterheadInspector
{
    /** @return array{readable: bool, pages: int, a4: bool, transparency: bool} */
    public function inspect(string $file): array
    {
        try {
            $pdf = new Tcpdf;
            $source = $pdf->setImportSourceData((string) file_get_contents($file));
            $pages = $pdf->getSourcePageCount($source);
            $page = $pdf->importPage($source, 1);
            // Seitengrösse in Punkt (1/72 Zoll) → mm
            $size = ['width' => $page->getWidth() * 25.4 / 72, 'height' => $page->getHeight() * 25.4 / 72];
        } catch (Throwable) {
            return ['readable' => false, 'pages' => 0, 'a4' => false, 'transparency' => false];
        }

        $a4 = abs((float) $size['width'] - 210) < 2 && abs((float) $size['height'] - 297) < 2;

        return ['readable' => true, 'pages' => (int) $pages, 'a4' => $a4, 'transparency' => self::hasTransparency((string) file_get_contents($file))];
    }

    public static function hasTransparency(string $pdf): bool
    {
        $content = $pdf;

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams) > 0) {
            foreach ($streams[1] as $stream) {
                $inflated = @gzuncompress($stream);
                $content .= is_string($inflated) ? "\n".$inflated : '';
            }
        }

        return preg_match('#/SMask\s*(?!/None)[/\d]#', $content) === 1
            || preg_match('#/(CA|ca)\s+(0?\.\d+|0)\b#', $content) === 1
            || preg_match('#/BM\s*/(?!Normal\b|Compatible\b)\w+#', $content) === 1;
    }
}
