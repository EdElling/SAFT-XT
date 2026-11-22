<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * App-Recht „Dokumente: Assistent nutzen“ (B-124, D2a): KI-Assistent für Dokumentvorlagen. Erhalten alle Rollen, die
 * Dokumente schon verwalten dürfen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $to = Permission::findOrCreate('documents.assistant', 'web')->id;
        $from = DB::table('permissions')->where('name', 'documents.manage')->where('guard_name', 'web')->value('id');

        if ($from !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $to, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'documents.assistant')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
