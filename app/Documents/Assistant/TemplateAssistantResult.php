<?php

declare(strict_types=1);

namespace App\Documents\Assistant;

/** Ergebnis einer Anfrage an den Vorlagen-Assistenten: Vorschlag, Rückfrage oder Fehler. */
final readonly class TemplateAssistantResult
{
    public const string TEMPLATE = 'template';

    public const string QUESTION = 'question';

    public const string ERROR = 'error';

    /**
     * @param  array{name: string, recipient: string, body: string, file_name: string}|null  $template
     * @param  list<array{role: string, content: string}>  $history  Gesprächsverlauf für Nachfragen
     */
    public function __construct(
        public string $status,
        public ?array $template = null,
        public ?string $message = null,
        public array $history = [],
    ) {}
}
