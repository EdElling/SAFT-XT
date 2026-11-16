<?php

declare(strict_types=1);

namespace App\Reports\Assistant;

/** Ergebnis einer Anfrage an den Assistenten: Vorschlag (Zustand des Editors), Rückfrage oder Fehler. */
final readonly class AssistantResult
{
    public const string REPORT = 'report';

    public const string QUESTION = 'question';

    public const string ERROR = 'error';

    /**
     * @param  array<string, mixed>|null  $state
     * @param  list<array{role: string, content: string}>  $history  Gesprächsverlauf für Nachfragen
     */
    public function __construct(
        public string $status,
        public ?array $state = null,
        public ?string $title = null,
        public ?string $message = null,
        public array $history = [],
    ) {}
}
