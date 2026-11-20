<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B-107, Etappe 6: Recht apps.upgrade – eine installierte App aus einem Paket aktualisieren (Änderungsplan). Wer Apps
 * installieren darf, darf sie auch aktualisieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        $upgrade = Permission::findOrCreate('apps.upgrade', 'web');
        $install = DB::table('permissions')->where('name', 'apps.install')->where('guard_name', 'web')->value('id');

        if ($install !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $install)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $upgrade->id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'apps.upgrade')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
