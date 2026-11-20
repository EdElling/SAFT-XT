<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B-107, Etappe 4b: Ausgangsstand je App – die Definition, wie sie installiert wurde (Bezugspunkt für das
 * Aktualisieren, Etappe 6) – und das Recht apps.install. Wer bisher Konfigurationen einspielen durfte
 * (configuration.import), darf Apps installieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('version', 30)->nullable();
            $table->longText('definition');
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['app_id', 'created_at']);
        });

        $install = Permission::findOrCreate('apps.install', 'web');
        $old = DB::table('permissions')->where('name', 'configuration.import')->where('guard_name', 'web')->value('id');

        if ($old !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $old)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $install->id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'apps.install')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('app_baselines');
    }
};
