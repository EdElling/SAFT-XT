<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Seite „Konfiguration übertragen“: Rechte configuration.view/export/import und Menüeintrag unter „Administration“. */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.pages.configuration-transfer';

    public function up(): void
    {
        foreach (['view', 'export', 'import'] as $action) {
            Permission::findOrCreate("configuration.{$action}", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');

        if ($admin === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => $admin,
            'label' => json_encode(['de' => 'Konfiguration übertragen', 'en' => 'Transfer configuration', 'fr' => 'Transférer la configuration', 'it' => 'Trasferire la configurazione'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-arrows-up-down',
            'permission_name' => 'configuration.view',
            'sort_order' => 81,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', ['configuration.view', 'configuration.export', 'configuration.import'])->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
