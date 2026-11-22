<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\Models\DocumentTemplate;

/**
 * Ergebnis einer Vorlage für einen Datensatz: Inhalt, Empfänger fürs Adressfeld, Dateiname, Sprache und die Datei –
 * das PDF oder (D4b) die Word-Fassung. $pdf ist bei Word leer, den Inhalt liefert content().
 */
final readonly class RenderedDocument
{
    public function __construct(
        public string $html,
        public ?string $recipient,
        public string $fileName,
        public string $locale,
        public string $pdf,
        public string $format = DocumentTemplate::FORMAT_PDF,
        public string $docx = '',
    ) {}

    public function isDocx(): bool
    {
        return $this->format === DocumentTemplate::FORMAT_DOCX;
    }

    /** Die erzeugte Datei (PDF oder DOCX). */
    public function content(): string
    {
        return $this->isDocx() ? $this->docx : $this->pdf;
    }

    public function mime(): string
    {
        return $this->isDocx() ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf';
    }
}
