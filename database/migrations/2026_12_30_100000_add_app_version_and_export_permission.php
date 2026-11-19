<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B-107, Etappe 4a: Version je App (frei wählbar, steht in der App-Definition) und das Recht apps.export. Wer bisher
 * Konfigurationen exportieren durfte (configuration.export), darf auch Apps exportieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('version', 30)->nullable()->after('prefix');
        });

        $export = Permission::findOrCreate('apps.export', 'web');
        $old = DB::table('permissions')->where('name', 'configuration.export')->where('guard_name', 'web')->value('id');

        if ($old !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $old)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $export->id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'apps.export')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
