<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\CustomTableLookup;
use App\Models\Base\UserTableColumn as BaseUserTableColumn;

/**
 * Gespeicherte Spaltenauswahl eines Benutzers für eine Tabelle (siehe RemembersTableColumns).
 *
 * Bewusst ohne Änderungsprotokoll: eine persönliche Ansichtseinstellung, keine fachliche Änderung.
 */
class UserTableColumn extends BaseUserTableColumn
{
    /**
     * Gespeicherte Abweichungen von der Vorgabe (Spalte => sichtbar); null = nichts gespeichert.
     *
     * @return array<string, bool>|null
     */
    public static function columnsFor(int $userId, string $key): ?array
    {
        $columns = self::stored($userId, $key);

        if (! is_array($columns)) {
            return null;
        }

        // Sortiert: MySQL speichert JSON-Objekte mit eigener Schlüsselreihenfolge. Die Reihenfolge der Spalten steht
        // unter ORDER (orderFor).
        unset($columns[self::ORDER]);
        $columns = array_map(static fn (mixed $v): bool => (bool) $v, $columns);
        ksort($columns);

        return $columns;
    }

    /** Schlüssel der gespeicherten Spaltenreihenfolge im JSON. */
    public const string ORDER = '_order';

    /**
     * Gespeicherte Reihenfolge der Spalten (Namen); null = Vorgabe.
     *
     * @return list<string>|null
     */
    public static function orderFor(int $userId, string $key): ?array
    {
        $columns = self::stored($userId, $key);
        $order = is_array($columns) ? ($columns[self::ORDER] ?? null) : null;

        return is_array($order) && $order !== [] ? array_values(array_map('strval', $order)) : null;
    }

    /**
     * Speichert die Abweichungen und eine abweichende Reihenfolge; keine Abweichung = Eintrag löschen (Vorgabe gilt).
     *
     * @param  array<string, bool>  $columns
     * @param  list<string>|null  $order
     */
    public static function remember(int $userId, string $key, array $columns, ?array $order = null): void
    {
        if ($order !== null) {
            $columns[self::ORDER] = $order;
        }

        app(CustomTableLookup::class)->forgetRemembered(self::rememberKey($userId));

        if ($columns === []) {
            self::query()->where('user_id', $userId)->where('table_key', $key)->delete();

            return;
        }

        self::query()->updateOrCreate(['user_id' => $userId, 'table_key' => $key], ['columns' => $columns]);
    }

    /**
     * Gespeichertes JSON; alle Einträge der Person je Request einmal gelesen. Laden und Speichern der Spaltenauswahl fragen
     * sonst je Tabelle bis zu viermal ab, die Kaskade zeigt mehrere Tabellen (B-150).
     */
    private static function stored(int $userId, string $key): mixed
    {
        $all = app(CustomTableLookup::class)->remember(self::rememberKey($userId), static fn (): array => self::query()
            ->where('user_id', $userId)->pluck('columns', 'table_key')->all());
        $columns = $all[$key] ?? null;

        return is_string($columns) ? json_decode($columns, true) : $columns;
    }

    private static function rememberKey(int $userId): string
    {
        return 'table-columns.'.$userId;
    }
}
