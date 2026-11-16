<?php

declare(strict_types=1);

namespace App\Models;

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
        $columns = self::query()->where('user_id', $userId)->where('table_key', $key)->value('columns');

        if (is_string($columns)) {
            $columns = json_decode($columns, true);
        }

        if (! is_array($columns)) {
            return null;
        }

        // Sortiert: MySQL speichert JSON-Objekte mit eigener Schlüsselreihenfolge.
        $columns = array_map(static fn (mixed $v): bool => (bool) $v, $columns);
        ksort($columns);

        return $columns;
    }

    /**
     * Speichert die Abweichungen; keine Abweichung = Eintrag löschen (Vorgabe gilt).
     *
     * @param  array<string, bool>  $columns
     */
    public static function remember(int $userId, string $key, array $columns): void
    {
        if ($columns === []) {
            self::query()->where('user_id', $userId)->where('table_key', $key)->delete();

            return;
        }

        self::query()->updateOrCreate(['user_id' => $userId, 'table_key' => $key], ['columns' => $columns]);
    }
}
