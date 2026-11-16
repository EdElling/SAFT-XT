<?php

declare(strict_types=1);

namespace App\DynamicData\Assistant;

/** Ergebnis einer Anfrage an den Tabellen-Assistenten. */
final readonly class TableAssistantResult
{
    public const string PROPOSAL = 'proposal';

    public const string QUESTION = 'question';

    public const string ERROR = 'error';

    /**
     * @param  array<string, mixed>|null  $proposal  neue Tabelle: Formularzustand; Änderungen: ['add' => [...], 'change' => [...]]
     * @param  list<string>  $summary  Änderungen in Worten (Vorschau)
     * @param  list<array{role: string, content: string}>  $history
     */
    public function __construct(
        public string $status,
        public ?array $proposal = null,
        public ?string $message = null,
        public array $summary = [],
        public array $history = [],
    ) {}
}
