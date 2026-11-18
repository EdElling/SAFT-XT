<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\UserTableState as BaseUserTableState;

/**
 * Gespeicherter Stand einer Datenansicht je Person (Filter, Sortierung, Suche, Zeilen pro Seite), siehe
 * RemembersTableState. Persönliche Ansichtseinstellung ohne Fachbezug – deshalb ohne Änderungsprotokoll, wie die
 * Spaltenauswahl (UserTableColumn).
 */
class UserTableState extends BaseUserTableState
{
    /** @return array<string, mixed>|null gespeicherter Stand; null = nichts gespeichert */
    public static function stateFor(int $userId, string $key): ?array
    {
        $state = self::query()->where('user_id', $userId)->where('table_key', $key)->value('state');

        if (is_string($state)) {
            $state = json_decode($state, true);
        }

        return is_array($state) && $state !== [] ? $state : null;
    }

    /**
     * Speichert den Stand; leer = Eintrag löschen (Vorgabe gilt).
     *
     * @param  array<string, mixed>  $state
     */
    public static function remember(int $userId, string $key, array $state): void
    {
        if ($state === []) {
            self::query()->where('user_id', $userId)->where('table_key', $key)->delete();

            return;
        }

        self::query()->updateOrCreate(['user_id' => $userId, 'table_key' => $key], ['state' => $state]);
    }
}
