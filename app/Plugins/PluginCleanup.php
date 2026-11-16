<?php

declare(strict_types=1);

namespace App\Plugins;

use App\DynamicData\DataPermissions;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Überbleibsel entfernter Plugins: Menüeinträge, deren Seite es nicht mehr gibt, und Rechte, die weder zu SAFT
 * noch zu einem installierten Plugin gehören. Gelöscht wird nur, was die Administration ausdrücklich auswählt.
 */
final readonly class PluginCleanup
{
    public function __construct(private PluginRegistry $plugins) {}

    /** @return array<int, string> Menüeinträge mit unbekannter Route: id => „Gruppe › Eintrag (route)“ */
    public function orphanedMenus(): array
    {
        return Menu::query()->with('parent')->whereNotNull('route')->orderBy('id')->get()
            ->reject(static fn (Menu $menu): bool => Route::has((string) $menu->route))
            ->mapWithKeys(static fn (Menu $menu): array => [
                (int) $menu->id => ($menu->parent !== null ? $menu->parent->label.' › ' : '').$menu->label." ({$menu->route})",
            ])
            ->all();
    }

    /** @return array<int, string> Rechte ohne SAFT-Bereich und ohne installiertes Plugin: id => Name */
    public function orphanedPermissions(): array
    {
        $installed = $this->plugins->permissionNames();

        return Permission::query()->where('guard_name', 'web')->where('name', 'not like', DataPermissions::PREFIX.'%')->orderBy('name')->pluck('name', 'id')
            ->reject(static fn (string $name): bool => in_array(Str::beforeLast($name, '.'), RoleForm::RESOURCES, true) || in_array($name, $installed, true))
            ->mapWithKeys(static fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
    }

    /**
     * Rollen, die die verwaisten Rechte noch haben – damit vor dem Löschen sichtbar ist, was verloren geht.
     *
     * @return array<int, list<string>> Recht-id => Rollennamen (alphabetisch); Rechte ohne Rolle fehlen
     */
    public function rolesOfOrphanedPermissions(): array
    {
        $ids = array_keys($this->orphanedPermissions());

        if ($ids === []) {
            return [];
        }

        return DB::table(config('permission.table_names.role_has_permissions'))
            ->join(config('permission.table_names.roles'), config('permission.table_names.roles').'.id', '=', config('permission.table_names.role_has_permissions').'.role_id')
            ->whereIn('permission_id', $ids)
            ->orderBy('name')
            ->get(['permission_id', 'name'])
            ->groupBy('permission_id')
            ->mapWithKeys(static fn ($rows, int|string $id): array => [(int) $id => $rows->pluck('name')->map(strval(...))->all()])
            ->all();
    }

    public function hasOrphans(): bool
    {
        return $this->orphanedMenus() !== [] || $this->orphanedPermissions() !== [];
    }

    /**
     * Löscht die ausgewählten Überbleibsel (nur solche, die tatsächlich verwaist sind) und danach leer gewordene
     * Menügruppen.
     *
     * @param  list<int>  $menuIds
     * @param  list<int>  $permissionIds
     * @return array{menus: int, permissions: int}
     */
    public function remove(array $menuIds, array $permissionIds): array
    {
        $menuIds = array_values(array_intersect($menuIds, array_keys($this->orphanedMenus())));
        $permissionIds = array_values(array_intersect($permissionIds, array_keys($this->orphanedPermissions())));

        DB::transaction(static function () use ($menuIds, $permissionIds): void {
            $menus = Menu::query()->whereIn('id', $menuIds)->get();
            $parents = $menus->pluck('parent_id')->filter()->unique();
            $menus->each->delete();

            // Gruppen ohne eigenes Ziel und ohne verbliebene Einträge.
            Menu::query()->whereIn('id', $parents)->whereNull('route')->whereNull('url')->whereDoesntHave('children')->get()->each->delete();

            Permission::query()->whereIn('id', $permissionIds)->get()->each->delete();
        });

        if ($permissionIds !== []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return ['menus' => count($menuIds), 'permissions' => count($permissionIds)];
    }
}
