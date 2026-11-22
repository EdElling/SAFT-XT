<?php

declare(strict_types=1);

namespace App\Documents\Templates;

/** Ergebnis einer Vorlage für einen Datensatz: Inhalt, Empfänger fürs Adressfeld, Dateiname, Sprache und das PDF. */
final readonly class RenderedDocument
{
    public function __construct(
        public string $html,
        public ?string $recipient,
        public string $fileName,
        public string $locale,
        public string $pdf,
    ) {}
}
