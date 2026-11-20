<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B-107, Etappe 4b: „Konfiguration übertragen“ ist abgelöst durch Export und Installieren von Apps. Menüeintrag und
 * Rechte configuration.* entfallen; wer sie hatte, hat bereits apps.export bzw. apps.install (frühere Migrationen).
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.pages.configuration-transfer';

    public function up(): void
    {
        $menus = DB::table('menus')->where('route', self::ROUTE)->pluck('id');

        if ($menus->isNotEmpty()) {
            DB::table('menu_role_hidden')->whereIn('menu_id', $menus)->delete();
            DB::table('menus')->whereIn('id', $menus)->delete();
        }

        Permission::query()->whereIn('name', ['configuration.view', 'configuration.export', 'configuration.import'])->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Die Seite gibt es nicht mehr; nur die Rechte zurück, damit ältere Stände wieder passen.
        foreach (['view', 'export', 'import'] as $action) {
            Permission::findOrCreate("configuration.{$action}", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
