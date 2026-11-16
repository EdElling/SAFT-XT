<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Seite „Plugins“: Rechte plugins.view/manage und Menüeintrag unter „Administration“. */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.pages.plugins';

    public function up(): void
    {
        foreach (['view', 'manage'] as $action) {
            Permission::findOrCreate("plugins.{$action}", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');

        if ($admin === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => $admin,
            'label' => json_encode(['de' => 'Plugins', 'en' => 'Plugins', 'fr' => 'Plugins', 'it' => 'Plugin'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-puzzle-piece',
            'permission_name' => 'plugins.view',
            'sort_order' => 79,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', ['plugins.view', 'plugins.manage'])->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
