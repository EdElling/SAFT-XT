<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Administration › Systemprotokoll (B-065): Recht system_log.view und Menüeintrag. */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.pages.system-log';

    public function up(): void
    {
        Permission::findOrCreate('system_log.view', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');

        if ($admin === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => $admin,
            'label' => json_encode(['de' => 'Systemprotokoll', 'en' => 'System log', 'fr' => 'Journal système', 'it' => 'Registro di sistema'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-document-text',
            'permission_name' => 'system_log.view',
            'sort_order' => 82,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->where('name', 'system_log.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
