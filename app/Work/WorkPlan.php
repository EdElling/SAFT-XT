<?php

declare(strict_types=1);

namespace App\Work;

/**
 * Ergebnis von WorkType::prepare(): Startzustand (Cursor) und Menge für den Fortschritt (null = unbekannt).
 *
 * @api
 */
final readonly class WorkPlan
{
    /** @param  array<string, mixed>  $state */
    public function __construct(public array $state = [], public ?int $total = null) {}
}
