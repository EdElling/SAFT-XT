<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Models\CustomTable;

/**
 * Tabellen des Table-Designers nach Namen, je Request einmal geladen (scoped im Container).
 *
 * Verknüpfungszellen, Detailtabellen und Protokoll-Links fragen dieselbe Tabelle sonst je Zeile und Zelle
 * neu ab (Lager-Demo: 462 gleiche Abfragen je Klick auf einen Lagerort, Messung 25.11.2026). Der Speicher
 * lebt nur einen Request bzw. Job lang; Speichern oder Löschen einer Tabelle und DynamicRecord::forget()
 * leeren ihn, damit Strukturänderungen im selben Request greifen.
 */
final class CustomTableLookup
{
    /** @var array<string, CustomTable|null> */
    private array $tables = [];

    /** Tabelle samt Feldern oder null, wenn es sie nicht (mehr) gibt. */
    public function byName(string $name): ?CustomTable
    {
        if (! array_key_exists($name, $this->tables)) {
            $this->tables[$name] = CustomTable::query()->with('fields')->where('name', $name)->first();
        }

        return $this->tables[$name];
    }

    public function forget(?string $name = null): void
    {
        if ($name === null) {
            $this->tables = [];
        } else {
            unset($this->tables[$name]);
        }
    }
}
