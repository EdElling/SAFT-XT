<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Arbeiten (Fundament 2, F3a): Rechte work.view_any (alle Arbeiten sehen), work.cancel_any (fremde abbrechen),
 * work.details (Fehlerdetails) und der Menüeintrag „Arbeiten“ unter Administration › Betrieb. Eigene Arbeiten sieht
 * jede Person über „Meine Arbeiten“ im Benutzermenü. Rollen mit tasks.details bekommen auch work.details.
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.resources.work-items.index';

    private const array PERMISSIONS = ['work.view_any', 'work.cancel_any', 'work.details'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $details = DB::table('permissions')->where('name', 'work.details')->where('guard_name', 'web')->value('id');
        $from = DB::table('permissions')->where('name', 'tasks.details')->where('guard_name', 'web')->value('id');

        if ($from !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $details, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');
        $parent = $admin === null ? null : DB::table('menus')->where('parent_id', $admin)->whereNull('route')->where('label->de', 'Betrieb')->value('id');

        if (($parent ?? $admin) === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $parent ?? $admin,
            'label' => json_encode(['de' => 'Arbeiten', 'en' => 'Jobs', 'fr' => 'Travaux', 'it' => 'Lavori'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-queue-list',
            'permission_name' => 'work.view_any',
            'sort_order' => 15,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
