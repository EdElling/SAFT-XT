<?php

declare(strict_types=1);

namespace App\Tasks;

/** Ergebnis eines erfolgreichen Laufs: Meldung für die Liste und Kennzahlen. */
final readonly class TaskResult
{
    /** @param  array<string, mixed>  $data */
    public function __construct(public string $message, public array $data = []) {}
}
