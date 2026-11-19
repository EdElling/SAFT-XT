<?php

declare(strict_types=1);

namespace App\Configuration;

use App\DynamicData\DataMenu;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\Parser;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Validation\FieldRuleCheck;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\User;
use App\Plugins\Api\ApiManifest;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\PluginRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Throwable;

/**
 * Konfigurationspaket „saft-config/1“: Tabellen des Table-Designers, Verknüpfungen für Auswertungen, Auswertungen,
 * Menüs und Rollen – alle Verweise als Namen (References), damit es in eine andere Installation passt. Keine
 * Datensätze, Benutzer, Zugangsdaten, App-Einstellungen; Auswertungen nur freigegebene und eigene.
 */
final class ConfigExporter
{
    public const string FORMAT = 'saft-config/1';

    public function __construct(
        private readonly References $refs,
        private readonly SchemaExporter $schema,
        private readonly PluginRegistry $plugins,
        private readonly Parser $parser,
    ) {}

    /** Auswertungen, die exportiert werden dürfen: freigegebene und eigene. */
    public static function exportableReports(?User $user): Builder
    {
        return Report::query()->where(static fn ($q) => $q->where('is_shared', true)->orWhere('owner_id', $user?->id ?? 0));
    }

    /**
     * @param  list<string>  $tables  technische Namen
     * @param  list<int>  $reports  IDs
     * @param  list<int>  $menus  IDs
     * @param  list<int>  $roles  IDs
     * @return array<string, mixed>
     */
    public function export(array $tables = [], array $reports = [], array $menus = [], array $roles = [], bool $dependencies = true, ?User $user = null, string $label = ''): array
    {
        $this->refs->refresh();
        $reports = self::exportableReports($user)->whereIn('id', $reports)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $menus = array_map('intval', $menus);
        $links = [];

        if ($dependencies) {
            [$tables, $reports, $menus, $links] = $this->withDependencies($tables, $reports, $menus, $user);
        }

        $all = CustomTable::query()->whereIn('name', $tables)->get();
        $designer = $all->reject(static fn (CustomTable $t): bool => $t->isExternal())->pluck('name')->all();
        $external = $all->filter(static fn (CustomTable $t): bool => $t->isExternal());

        $package = [
            'format' => self::FORMAT,
            'label' => $label,
            'saft' => (string) config('saft.version'),
            'api' => ApiManifest::current(),
            'created_at' => now()->toIso8601String(),
            'source' => (string) config('app.name'),
            'requires' => ['plugins' => [], 'connections' => $external->map(static fn (CustomTable $t): ?string => $t->databaseConnection?->name)->filter()->unique()->values()->all()],
            'tables' => $designer === [] ? [] : $this->schema->export($designer, false)['tables'],
            'external_tables' => $external->pluck('name')->sort()->values()->all(),
            'report_links' => ReportLink::query()->whereIn('id', $links)->orderBy('id')->get()->map(fn (ReportLink $l): array => $this->link($l))->all(),
            'reports' => Report::query()->whereIn('id', $reports)->orderBy('name')->get()->map(fn (Report $r): array => $this->report($r))->all(),
            'menus' => $this->menus($menus),
            'roles' => Role::query()->whereIn('id', $roles)->with(['permissions', 'hiddenMenus'])->orderBy('name')->get()->map(fn (Role $r): array => $this->role($r))->all(),
        ];

        $package['requires']['plugins'] = $this->requiredPlugins($package);

        return $package;
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

    /**
     * Menüeinträge mit allen Vorfahren, Eltern vor Kindern.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function menus(array $ids): array
    {
        $menus = Menu::query()->get()->keyBy('id');
        $selected = [];

        foreach ($ids as $id) {
            for ($current = $menus->get($id); $current !== null; $current = $menus->get((int) $current->parent_id)) {
                $selected[(int) $current->id] = $current;
            }
        }

        $rows = array_map(fn (Menu $m): array => $this->menu($m), array_values($selected));
        usort($rows, static fn (array $a, array $b): int => [substr_count($a['path'], ' › '), $a['sort_order'], $a['path']] <=> [substr_count($b['path'], ' › '), $b['sort_order'], $b['path']]);

        return $rows;
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
     * Abhängigkeiten ergänzen: Auswertungen → Tabellen und Verknüpfungen, Menüeinträge → Tabelle bzw. Auswertung,
     * Tabellen → Zieltabellen ihrer Verknüpfungen und Kind-Tabellen ihrer Formeln.
     *
     * @param  list<string>  $tables
     * @param  list<int>  $reports
     * @param  list<int>  $menus
     * @return array{0: list<string>, 1: list<int>, 2: list<int>, 3: list<int>}
     */
    private function withDependencies(array $tables, array $reports, array $menus, ?User $user): array
    {
        $links = [];

        foreach (Menu::query()->whereIn('id', $menus)->get() as $menu) {
            $parameters = (array) ($menu->route_parameters ?? []);

            if ($menu->route === DataMenu::ROUTE && isset($parameters['table'])) {
                $tables[] = (string) $parameters['table'];
            } elseif ($menu->route === ReportResource::VIEW_ROUTE && isset($parameters['record'])) {
                $reports[] = (int) $parameters['record'];
            }
        }

        $reports = self::exportableReports($user)->whereIn('id', $reports)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        foreach (Report::query()->whereIn('id', $reports)->get() as $report) {
            ['tables' => $used, 'links' => $via] = $this->reportUses($report);
            $tables = [...$tables, ...$used];
            $links = [...$links, ...$via];
        }

        // Tabellen: Verknüpfungsziele und Kind-Tabellen von Formeln, transitiv.
        $known = CustomTable::query()->pluck('name')->all();
        $queue = array_values(array_unique(array_filter($tables)));
        $tables = [];

        while ($queue !== []) {
            $name = array_shift($queue);

            if (in_array($name, $tables, true) || ! in_array($name, $known, true)) {
                continue;
            }

            $tables[] = $name;

            foreach (CustomField::query()->whereHas('custom_table', static fn ($q) => $q->where('name', $name))->get() as $field) {
                if ($field->type === FieldType::Relation) {
                    $queue[] = (string) ($field->optionsArray()['table'] ?? '');
                } elseif ($field->type === FieldType::Computed) {
                    $queue = [...$queue, ...$this->formulaTables((string) ($field->optionsArray()['formula'] ?? ''))];
                }
            }
        }

        sort($tables);

        return [$tables, array_values(array_unique($reports)), $menus, array_values(array_unique($links))];
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

    /** @return list<string> Kind-Tabellen, über die eine Formel zusammenfasst */
    private function formulaTables(string $formula): array
    {
        try {
            $ast = $formula === '' ? [] : $this->parser->parse($formula);
        } catch (Throwable) {
            return [];
        }

        $found = [];
        $walk = static function (array $node) use (&$walk, &$found): void {
            if (isset($node['path'][0])) {
                $found[] = (string) $node['path'][0];
            }

            if (in_array($node['call'] ?? null, FormulaAnalyzer::AGGREGATES, true) && isset($node['args'][0]['field'])) {
                $found[] = (string) $node['args'][0]['field'];
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($ast);

        return $found;
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
