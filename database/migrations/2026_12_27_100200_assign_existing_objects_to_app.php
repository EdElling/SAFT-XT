<?php

declare(strict_types=1);

use App\Apps\SystemObjects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * B-107, minimale Umstellung: Gibt es schon fachliche Objekte, kommen sie alle in eine App „Bestehende Daten“ –
 * Tabellen, Auswertungen, Tabellenverknüpfungen, Rollen (ausser Systemrollen), Aufgaben (ausser System-Aufgaben) und
 * Menüs (ausser System-Menüs). Aufgeteilt wird danach von Hand („In andere App verschieben“). Neue Installationen
 * erhalten hier keine App.
 */
return new class extends Migration
{
    public const array NAME = ['de' => 'Bestehende Daten', 'en' => 'Existing data', 'fr' => 'Données existantes', 'it' => 'Dati esistenti'];

    public function up(): void
    {
        $roles = DB::table('roles')->whereNull('app_id')->whereNotIn('name', SystemObjects::roles())->pluck('id');
        $tasks = DB::table('scheduled_tasks')->whereNull('app_id')->where('is_system', false)->pluck('id');
        $menus = $this->appMenus();

        $empty = $roles->isEmpty() && $tasks->isEmpty() && $menus === []
            && ! DB::table('custom_tables')->exists() && ! DB::table('reports')->exists() && ! DB::table('report_links')->exists();

        if ($empty) {
            return;
        }

        $now = now();
        $app = DB::table('apps')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => json_encode(self::NAME, JSON_UNESCAPED_UNICODE),
            'icon' => 'heroicon-o-archive-box',
            'sort_order' => 100,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (['custom_tables', 'reports', 'report_links'] as $table) {
            DB::table($table)->whereNull('app_id')->update(['app_id' => $app]);
        }

        DB::table('roles')->whereIn('id', $roles)->update(['app_id' => $app]);
        DB::table('scheduled_tasks')->whereIn('id', $tasks)->update(['app_id' => $app]);

        foreach (array_chunk($menus, 500) as $ids) {
            DB::table('menus')->whereIn('id', $ids)->update(['app_id' => $app]);
        }
    }

    public function down(): void
    {
        foreach (['custom_tables', 'reports', 'report_links', 'roles', 'scheduled_tasks', 'menus'] as $table) {
            DB::table($table)->update(['app_id' => null]);
        }

        DB::table('apps')->delete();
    }

    /**
     * Menüeinträge, die zu einer App gehören: alle ausser solchen, die eine System-Seite öffnen, und Gruppen, die
     * irgendwo eine System-Seite enthalten (z. B. die Administration).
     *
     * @return list<int>
     */
    private function appMenus(): array
    {
        $menus = DB::table('menus')->get(['id', 'parent_id', 'route']);
        $children = $menus->groupBy('parent_id');
        $containsSystem = [];

        $check = function (object $menu) use (&$check, &$containsSystem, $children): bool {
            if (array_key_exists($menu->id, $containsSystem)) {
                return $containsSystem[$menu->id];
            }

            $result = SystemObjects::isSystemRoute($menu->route);

            foreach ($children->get($menu->id, collect()) as $child) {
                $result = $check($child) || $result;
            }

            return $containsSystem[$menu->id] = $result;
        };

        return $menus->reject(static fn (object $menu): bool => $check($menu))->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
};
