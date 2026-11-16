<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use App\Models\CustomTable;
use App\Models\Menu;

/** Ergebnis eines Imports: neue Tabelle, Anzahl Datensätze, ggf. Menüeintrag. */
final readonly class ImportResult
{
    public function __construct(
        public CustomTable $table,
        public int $rows,
        public ?Menu $menu = null,
    ) {}
}
