<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\DynamicData\History\LoggedChanges;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Widgets mit Protokolleinträgen: die verknüpften Datensätze aller angezeigten Einträge einmal gesammelt nachschlagen,
 * bevor die Kurzfassungen je Zeile entstehen (B-174, eine Abfrage je verknüpfter Tabelle statt je Zeile).
 */
trait PrimesLoggedChanges
{
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        app(LoggedChanges::class)->prime($records instanceof Collection ? $records : collect($records->items()));

        return $records;
    }
}
