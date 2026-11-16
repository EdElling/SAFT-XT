<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

/** Fehlerzelle aus Excel (z. B. #DIV/0! oder eine Formel ohne gespeichertes Ergebnis). */
final readonly class CellError
{
    public function __construct(public string $value) {}
}
