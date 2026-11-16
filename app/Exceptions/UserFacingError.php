<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Nutzerfreundliche, technikfreie Darstellung eines Fehlers.
 */
final readonly class UserFacingError
{
    /**
     * @param  array<string, list<string>>  $fields  Feldbezogene Meldungen (Validierung)
     * @param  array<string, mixed>|null  $debug  Technische Details – nur bei APP_DEBUG
     */
    public function __construct(
        public string $code,
        public string $title,
        public string $message,
        public int $status,
        public ?string $reference = null,
        public array $fields = [],
        public bool $expected = false,
        public ?array $debug = null,
    ) {}

    /** Meldung inkl. Referenz für die Anzeige. */
    public function body(): string
    {
        return $this->reference === null
            ? $this->message
            : $this->message.' '.__('errors.reference', ['reference' => $this->reference]);
    }

    /**
     * @return array{error: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['error' => array_filter([
            'code' => $this->code,
            'title' => $this->title,
            'message' => $this->message,
            'status' => $this->status,
            'reference' => $this->reference,
            'fields' => $this->fields === [] ? null : $this->fields,
            'debug' => $this->debug,
        ], static fn (mixed $value): bool => $value !== null)];
    }
}
