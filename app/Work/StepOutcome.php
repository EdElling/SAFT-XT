<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Ergebnis eines Schritts: weiter mit neuem Zustand oder fertig mit Ergebnis. $done ist die Menge, die dieser
 * Schritt erledigt hat (wird zum Fortschritt addiert).
 *
 * @api
 */
final readonly class StepOutcome
{
    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $result
     */
    private function __construct(
        public bool $finished,
        public array $state,
        public int $done,
        public array $result,
        public ?string $message,
    ) {}

    /** @param  array<string, mixed>  $state  Zustand für den nächsten Schritt */
    public static function continue(array $state, int $done = 0): self
    {
        return new self(false, $state, $done, [], null);
    }

    /**
     * @param  array<string, mixed>  $result  Kennzahlen; „file“ (Name im Ordner der Arbeit) bietet die Seite „Arbeiten“
     *                                        zum Herunterladen an
     */
    public static function finished(string $message, array $result = [], int $done = 0): self
    {
        return new self(true, [], $done, $result, $message);
    }
}
