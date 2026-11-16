<?php

declare(strict_types=1);

namespace App\Plugins;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Legt die Rechte der installierten Plugins an (nach jedem „migrate“ und mit „saft:plugins“).
 * Rechte werden nie automatisch gelöscht: Rollen behalten ihre Zuweisung, falls ein Plugin wiederkommt.
 */
final readonly class PluginPermissions
{
    public function __construct(private PluginRegistry $plugins) {}

    /** @return list<string> neu angelegte Rechte */
    public function sync(): array
    {
        if ($this->plugins->all() === [] || ! Schema::hasTable((string) config('permission.table_names.permissions', 'permissions'))) {
            return [];
        }

        $created = [];

        foreach ($this->plugins->permissionNames() as $name) {
            if (Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        if ($created !== []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $created;
    }
}
