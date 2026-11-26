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
 *
 * Daneben merkt er sich weitere aus dem Schema abgeleitete Angaben (remember()): Details einer Tabelle,
 * abhängige berechnete Felder, Dokumentvorlagen, Auswahllisten. Jede Detailtabelle der Kaskade fragt sie sonst
 * erneut ab (Lager-Demo: 58 Abfragen je Klick, davon rund 30 gleiche, B-150). Speichern oder Löschen einer
 * Tabelle, eines Felds, einer Tabellenverknüpfung oder einer Dokumentvorlage leert alles.
 */
final class CustomTableLookup
{
    /** @var array<string, CustomTable|null> */
    private array $tables = [];

    /** @var array<string, mixed> */
    private array $remembered = [];

    /** Tabelle samt Feldern oder null, wenn es sie nicht (mehr) gibt. */
    public function byName(string $name): ?CustomTable
    {
        if (! array_key_exists($name, $this->tables)) {
            $this->tables[$name] = CustomTable::query()->with('fields')->where('name', $name)->first();
        }

        return $this->tables[$name];
    }

    /**
     * Abgeleitete Angabe je Request einmal berechnen.
     *
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    public function remember(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, $this->remembered)) {
            $this->remembered[$key] = $compute();
        }

        return $this->remembered[$key];
    }

    /** Eine gemerkte Angabe verwerfen, z. B. nach dem Speichern der Spaltenauswahl. */
    public function forgetRemembered(string $key): void
    {
        unset($this->remembered[$key]);
    }

    public function forget(?string $name = null): void
    {
        $this->remembered = [];

        if ($name === null) {
            $this->tables = [];
        } else {
            unset($this->tables[$name]);
        }
    }
}
