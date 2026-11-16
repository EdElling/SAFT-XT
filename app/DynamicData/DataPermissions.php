<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Models\CustomTable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions für die Datensätze einer Designer-Tabelle: data.<tabelle>.<aktion>.
 * Werden mit der Tabelle angelegt und mit ihr entfernt (inkl. Rollen-Zuweisungen).
 */
final class DataPermissions
{
    public const array ACTIONS = ['view', 'create', 'update', 'delete', 'export', 'import'];

    public const string PREFIX = 'data.';

    /**
     * @return list<string>
     */
    public static function names(string $tableName): array
    {
        return array_map(static fn (string $action): string => self::PREFIX."{$tableName}.{$action}", self::ACTIONS);
    }

    public static function name(string $tableName, string $action): string
    {
        return self::PREFIX."{$tableName}.{$action}";
    }

    public function ensure(CustomTable $table): void
    {
        foreach (self::names($table->name) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function remove(CustomTable $table): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', self::names($table->name))->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
