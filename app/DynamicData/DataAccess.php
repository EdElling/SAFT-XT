<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Models\CustomTable;
use App\Models\User;

/**
 * Rechte auf Datensätze einer Designer-Tabelle (data.<tabelle>.<aktion>).
 * Inaktive Tabellen sind für niemanden nutzbar.
 */
final class DataAccess
{
    public static function allows(?User $user, CustomTable $table, string $action): bool
    {
        return $user !== null
            && $table->is_active
            && in_array($action, DataPermissions::ACTIONS, true)
            && $table->supports($action)
            && $user->can(DataPermissions::name($table->name, $action));
    }

    public static function current(CustomTable $table, string $action): bool
    {
        $user = auth()->user();

        return self::allows($user instanceof User ? $user : null, $table, $action);
    }

    /** Export braucht die Freigabe in den Stammdaten und das Recht der Rolle, dazu Leserecht. */
    public static function canExport(CustomTable $table): bool
    {
        return $table->export_enabled && self::current($table, 'view') && self::current($table, 'export');
    }

    /** Datenimport (nur neue Datensätze): Freigabe in den Stammdaten, Recht „importieren“ und „anlegen“, dazu Leserecht. */
    public static function canImport(CustomTable $table): bool
    {
        return $table->import_enabled && ! $table->isExternal()
            && self::current($table, 'view') && self::current($table, 'create') && self::current($table, 'import');
    }
}
