<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rechte und Menüeintrag „Administration › Datenbankverbindungen“ für bestehende Installationen.
 * Kein Benutzer erhält die Rechte automatisch; super-admin hat sie implizit.
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.resources.database-connections.index';

    public function up(): void
    {
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::findOrCreate("database_connections.{$action}", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');

        if ($admin === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => $admin,
            'label' => json_encode(['de' => 'Datenbankverbindungen', 'en' => 'Database connections', 'fr' => 'Connexions aux bases de données', 'it' => 'Connessioni ai database'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-circle-stack',
            'permission_name' => 'database_connections.view',
            'sort_order' => 75,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->where('guard_name', 'web')->where('name', 'like', 'database_connections.%')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
