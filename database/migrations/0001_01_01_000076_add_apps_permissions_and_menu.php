<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Administration › Anwendung › Apps (B-107): Rechte apps.view und apps.manage und Menüeintrag. */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.resources.apps.index';

    public function up(): void
    {
        Permission::findOrCreate('apps.view', 'web');
        Permission::findOrCreate('apps.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');
        $parent = $admin === null ? null : DB::table('menus')->where('parent_id', $admin)->whereNull('route')->where('label->de', 'Anwendung')->value('id');

        if (($parent ?? $admin) === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent ?? $admin,
            'label' => json_encode(['de' => 'Apps', 'en' => 'Apps', 'fr' => 'Apps', 'it' => 'App'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-squares-2x2',
            'permission_name' => 'apps.view',
            'sort_order' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->whereIn('name', ['apps.view', 'apps.manage'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
