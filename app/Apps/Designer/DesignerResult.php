<?php

declare(strict_types=1);

namespace App\Apps\Designer;

/** Ergebnis einer Runde im App-Designer: geprüfter Vorschlag (im Entwurf gespeichert), Rückfrage oder Fehler. */
final readonly class DesignerResult
{
    public const string PROPOSAL = 'proposal';

    public const string QUESTION = 'question';

    public const string ERROR = 'error';

    /** @param  list<string>  $warnings  Hinweise der Installationsanalyse (z. B. Dateifelder) */
    public function __construct(
        public string $status,
        public ?string $message = null,
        public array $warnings = [],
    ) {}
}
