<?php

declare(strict_types=1);

namespace App\Documents;

use Com\Tecnick\Pdf\Tcpdf;

/**
 * Mehrere PDFs zu einem zusammenfügen (B-124, D3a): alle Seiten nacheinander, je Seite unverändert (Briefpapier, Adressfeld
 * und „Seite x / y“ bleiben die des einzelnen Dokuments). Mit pdfa entsteht wieder ein PDF/A-3b.
 */
final class PdfMerger
{
    /** @param  list<string>  $pdfs */
    public function merge(array $pdfs, bool $pdfa, string $title = ''): string
    {
        $pdf = new Tcpdf(
            unit: 'mm',
            isunicode: true,
            subsetfont: true,
            compress: true,
            mode: $pdfa ? 'pdfa3b' : '',
            fileOptions: ['allowedHosts' => [], 'allowedPaths' => []],
        );
        $pdf->setTitle($title);
        $pdf->setCreator('SAFT XT');

        foreach ($pdfs as $data) {
            $source = $pdf->setImportSourceData($data);

            for ($page = 1, $count = $pdf->getSourcePageCount($source); $page <= $count; $page++) {
                $pdf->addPageFromImport($source, $page);
            }
        }

        return $pdf->getOutPDFString();
    }
}
