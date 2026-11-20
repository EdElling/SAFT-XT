<?php

declare(strict_types=1);

namespace App\Apps\Definition;

use App\DynamicData\DataMenu;
use App\DynamicData\Validation\FieldRuleCheck;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use ReflectionClass;

/**
 * Bausteine der App-Definition (B-107): Tabellenverknüpfungen, Auswertungen, Menüs und Rollen mit Verweisen als Namen
 * (References), benötigte Plugins und die von einer Auswertung verwendeten Tabellen. Hervorgegangen aus dem früheren
 * Konfigurationspaket („Konfiguration übertragen“, abgelöst durch Export und Installieren von Apps).
 */
final class DefinitionParts
{
    public function __construct(
        private readonly References $refs,
        private readonly PluginRegistry $plugins,
    ) {}

    /** Auswertungen, die exportiert werden dürfen: freigegebene und eigene. */
    public static function exportableReports(?User $user): Builder
    {
        return Report::query()->where(static fn ($q) => $q->where('is_shared', true)->orWhere('owner_id', $user?->id ?? 0));
    }

    /** @return array<string, mixed> */
    public function link(ReportLink $link): array
    {
        return [
            'label' => $link->getTranslations('label'),
            'source' => $this->refs->field((int) $link->source_field_id),
            'target_table' => $this->refs->table((int) $link->target_table_id),
            'target_field' => $link->target_field_id === null ? null : $this->refs->field((int) $link->target_field_id),
        ];
    }

    /** @return array<string, mixed> */
    public function report(Report $report): array
    {
        $definition = (array) ($report->definition ?? []);
        $definition['base'] = $this->refs->table((int) ($definition['base'] ?? 0));
        $definition['fields'] = array_map(fn (mixed $f): mixed => is_array($f) ? [
            ...$f,
            'path' => array_map(fn (mixed $step): ?string => (int) $step < 0 ? 'link:'.$this->refs->link(-(int) $step) : $this->refs->field((int) $step), (array) ($f['path'] ?? [])),
            'field' => $this->refs->field((int) ($f['field'] ?? 0)),
        ] : $f, (array) ($definition['fields'] ?? []));

        return [
            'name' => (string) $report->name,
            'description' => $report->description,
            'is_shared' => (bool) $report->is_shared,
            'definition' => $definition,
        ];
    }

    /** @return array<string, mixed> */
    public function menu(Menu $menu): array
    {
        $parameters = (array) ($menu->route_parameters ?? []);

        $target = match (true) {
            $menu->route === DataMenu::ROUTE => ['type' => 'data', 'table' => (string) ($parameters['table'] ?? ''),
                'columns' => array_values(array_filter(array_map(fn (mixed $id): ?string => $this->refs->field((int) $id), (array) (($menu->view_config ?? [])['columns'] ?? []))))],
            $menu->route === ReportResource::VIEW_ROUTE => ['type' => 'report', 'report' => Report::query()->whereKey($parameters['record'] ?? 0)->value('name')],
            // Parameter „menu“ (z. B. Datenmodell einer Menügruppe) zeigt auf einen Menüeintrag: als Pfad.
            $menu->route !== null => ['type' => 'route', 'route' => $menu->route, 'parameters' => isset($parameters['menu']) && is_numeric($parameters['menu'])
                ? [...$parameters, 'menu' => 'menu:'.$this->refs->menuPath((int) $parameters['menu'])] : $parameters],
            $menu->url !== null => ['type' => 'url', 'url' => $menu->url],
            default => ['type' => 'group'],
        };

        return [
            'path' => (string) $this->refs->menuPath((int) $menu->id),
            'parent' => $menu->parent_id === null ? null : $this->refs->menuPath((int) $menu->parent_id),
            'label' => $menu->getTranslations('label'),
            'icon' => $menu->icon,
            'permission_name' => $menu->permission_name,
            'sort_order' => (int) $menu->sort_order,
            'is_active' => (bool) $menu->is_active,
            'target' => $target,
        ];
    }

    /** @return array<string, mixed> */
    public function role(Role $role): array
    {
        return [
            'name' => (string) $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'hidden_menus' => $role->hiddenMenus->map(fn (Menu $m): ?string => $this->refs->menuPath((int) $m->id))->filter()->sort()->values()->all(),
        ];
    }

    /**
     * Tabellen (Grundtabelle zuerst) und Verknüpfungen für Auswertungen, die eine Auswertung verwendet.
     *
     * @return array{tables: list<string>, links: list<int>}
     */
    public function reportUses(Report $report): array
    {
        $definition = (array) ($report->definition ?? []);
        $tables = [(string) $this->refs->table((int) ($definition['base'] ?? 0))];
        $links = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            foreach ([...(array) ($field['path'] ?? []), $field['field'] ?? 0] as $step) {
                if ((int) $step < 0) {
                    $links[] = -(int) $step;
                } elseif (($reference = $this->refs->field((int) $step)) !== null) {
                    $tables[] = (string) strstr($reference, '.', true);
                }
            }
        }

        foreach (ReportLink::query()->whereIn('id', $links)->get() as $link) {
            $tables[] = (string) $this->refs->table((int) $link->source_table_id);
            $tables[] = (string) $this->refs->table((int) $link->target_table_id);
        }

        return ['tables' => array_values(array_unique(array_filter($tables))), 'links' => array_values(array_unique($links))];
    }

    /**
     * Plugins, ohne die Teile des Pakets nicht funktionieren: Rechte und Menürechte eines Plugins, Menüeinträge auf
     * Seiten eines Plugins, Prüfregeln und Formelfunktionen in Feldern.
     *
     * @param  array<string, mixed>  $package  braucht roles, menus und tables (auch App-Definition, B-107)
     * @return array<string, string> Kennung => installierte Version
     */
    public function requiredPlugins(array $package): array
    {
        $ids = [];
        $permissions = [...array_merge([], ...array_column($package['roles'], 'permissions')), ...array_filter(array_column($package['menus'], 'permission_name'))];

        foreach ($permissions as $permission) {
            if (($plugin = $this->plugins->forPermission((string) $permission)) !== null) {
                $ids[] = $plugin->id();
            }
        }

        foreach ($package['menus'] as $menu) {
            if (($menu['target']['type'] ?? null) === 'route' && ($id = $this->pluginOfRoute((string) $menu['target']['route'])) !== null) {
                $ids[] = $id;
            }
        }

        $functions = [];

        foreach ($this->plugins->all() as $id => $plugin) {
            foreach ($plugin->formulaFunctions() as $function) {
                $functions[$function->name] ??= $id;
            }
        }

        foreach ($package['tables'] as $table) {
            foreach ((array) $table['fields'] as $field) {
                foreach ((array) (($field['validation_rules'] ?? [])[FieldRuleCheck::OPTION] ?? []) as $check) {
                    $ids[] = (string) strstr((string) $check, '.', true);
                }

                if (($field['type'] ?? null) === 'computed') {
                    foreach ($functions as $name => $id) {
                        if (preg_match('/\b'.preg_quote($name, '/').'\s*\(/', (string) ($field['options']['formula'] ?? '')) === 1) {
                            $ids[] = $id;
                        }
                    }
                }
            }
        }

        $required = [];

        foreach (array_unique($ids) as $id) {
            $required[$id] = $this->plugins->has($id) ? $this->plugins->get($id)->version() : '';
        }

        ksort($required);

        return $required;
    }

    /** Kennung des Plugins, dessen Seite oder Ressource die Route bedient. */
    private function pluginOfRoute(string $name): ?string
    {
        $class = Route::getRoutes()->getByName($name)?->getAction('controller');

        if (! is_string($class)) {
            return null;
        }

        foreach ($this->plugins->all() as $id => $plugin) {
            if (str_starts_with($class, (new ReflectionClass($plugin))->getNamespaceName().'\\')) {
                return $id;
            }
        }

        return null;
    }
}
