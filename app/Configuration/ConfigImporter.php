<?php

declare(strict_types=1);

namespace App\Configuration;

use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\DatabaseConnection;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\User;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\Context\SchemaImporter;
use App\Plugins\PluginRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Konfigurationspaket einspielen (Etappe 1: nur Neues). analyze() vergleicht jeden Eintrag mit der Installation –
 * neu, identisch, abweichend oder fehlende Voraussetzung; apply() legt nur die neuen an: Tabellen (in Durchgängen),
 * Verknüpfungen für Auswertungen, Auswertungen, Menüs, Rollen. Verweise werden über Namen aufgelöst (References).
 */
final class ConfigImporter
{
    /** Höchstgrösse einer Paketdatei. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly References $refs,
        private readonly ConfigExporter $exporter,
        private readonly SchemaExporter $schema,
        private readonly SchemaImporter $schemaImporter,
        private readonly PluginRegistry $plugins,
    ) {}

    /**
     * Datei lesen und prüfen.
     *
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    public function read(string $path): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new BusinessRuleException(__('configuration.errors.size', ['max' => self::MAX_BYTES / 1024 / 1024]));
        }

        $package = json_decode((string) file_get_contents($path), true);

        if (! is_array($package)) {
            throw new BusinessRuleException(__('configuration.errors.json'));
        }

        $this->validate($package);

        return $package;
    }

    /** @param  array<string, mixed>  $package */
    public function validate(array $package): void
    {
        if (($package['format'] ?? null) !== ConfigExporter::FORMAT) {
            throw new BusinessRuleException(__('configuration.errors.format', ['format' => (string) json_encode($package['format'] ?? null), 'expected' => ConfigExporter::FORMAT]));
        }

        foreach (['tables', 'report_links', 'reports', 'menus', 'roles'] as $key) {
            if (! is_array($package[$key] ?? []) || ! array_is_list($package[$key] ?? [])) {
                throw new BusinessRuleException(__('configuration.errors.structure', ['part' => $key]));
            }
        }
    }

    /**
     * Voraussetzungen, die in dieser Installation fehlen: Plugins und Datenbankverbindungen.
     *
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    public function missingRequirements(array $package): array
    {
        $missing = [];

        foreach (array_keys((array) ($package['requires']['plugins'] ?? [])) as $id) {
            if (! $this->plugins->has((string) $id)) {
                $missing[] = __('configuration.requirements.plugin', ['plugin' => $id]);
            }
        }

        foreach ((array) ($package['requires']['connections'] ?? []) as $name) {
            if (! DatabaseConnection::query()->where('name', $name)->exists()) {
                $missing[] = __('configuration.requirements.connection', ['connection' => $name]);
            }
        }

        foreach ((array) ($package['external_tables'] ?? []) as $name) {
            if ($this->refs->tableId((string) $name) === null) {
                $missing[] = __('configuration.requirements.external_table', ['table' => $name]);
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<ImportItem>
     */
    public function analyze(array $package): array
    {
        $this->validate($package);
        $this->refs->refresh();
        $items = [];
        $newTables = [];

        foreach ($package['tables'] as $table) {
            $items[] = $item = $this->analyzeTable($table, $package);

            if ($item->status === ImportItem::NEW) {
                $newTables[$table['name']] = array_column((array) $table['fields'], 'name');
            }
        }

        $hasTable = fn (string $name): bool => isset($newTables[$name]) || $this->refs->tableId($name) !== null;
        $hasField = function (?string $reference) use ($newTables): bool {
            [$table, $field] = explode('.', (string) $reference, 2) + [1 => ''];

            return in_array($field, $newTables[$table] ?? [], true) || $this->refs->fieldId((string) $reference) !== null;
        };

        $newLinks = [];

        foreach ($package['report_links'] as $link) {
            $key = $link['source'].'>'.$link['target_table'];
            $label = References::defaultLabel($link['label'] ?? []) ?: $key;
            $existing = $this->refs->linkId($key);

            if ($existing !== null) {
                $current = $this->exporterLink((int) $existing);
                $items[] = new ImportItem(ImportItem::REPORT_LINK, $key, $label, self::same($current, $link) ? ImportItem::IDENTICAL : ImportItem::DIFFERENT);

                continue;
            }

            $notes = array_values(array_filter([
                $hasField($link['source']) ? null : __('configuration.notes.field', ['field' => $link['source']]),
                $hasTable((string) $link['target_table']) ? null : __('configuration.notes.table', ['table' => $link['target_table']]),
                $link['target_field'] === null || $hasField($link['target_field']) ? null : __('configuration.notes.field', ['field' => $link['target_field']]),
            ]));
            $items[] = new ImportItem(ImportItem::REPORT_LINK, $key, $label, $notes === [] ? ImportItem::NEW : ImportItem::MISSING, $notes);

            if ($notes === []) {
                $newLinks[] = $key;
            }
        }

        $newReports = [];

        foreach ($package['reports'] as $report) {
            $existing = $this->refs->reportId((string) $report['name']);

            if ($existing !== null) {
                $current = $this->exporter->report(Report::query()->findOrFail($existing));
                $items[] = new ImportItem(ImportItem::REPORT, $report['name'], $report['name'], self::same($current, $report) ? ImportItem::IDENTICAL : ImportItem::DIFFERENT);

                continue;
            }

            $notes = [];
            $definition = (array) $report['definition'];

            if (! $hasTable((string) ($definition['base'] ?? ''))) {
                $notes[] = __('configuration.notes.table', ['table' => $definition['base'] ?? '?']);
            }

            foreach ((array) ($definition['fields'] ?? []) as $field) {
                foreach ([...(array) ($field['path'] ?? []), $field['field'] ?? null] as $step) {
                    $step = (string) $step;

                    if (str_starts_with($step, 'link:')) {
                        $link = substr($step, 5);

                        if (! in_array($link, $newLinks, true) && $this->refs->linkId($link) === null) {
                            $notes[] = __('configuration.notes.link', ['link' => $link]);
                        }
                    } elseif (! $hasField($step)) {
                        $notes[] = __('configuration.notes.field', ['field' => $step]);
                    }
                }
            }

            $notes = array_values(array_unique($notes));
            $items[] = new ImportItem(ImportItem::REPORT, $report['name'], $report['name'], $notes === [] ? ImportItem::NEW : ImportItem::MISSING, $notes);

            if ($notes === []) {
                $newReports[] = (string) $report['name'];
            }
        }

        $newMenus = [];

        foreach ($package['menus'] as $menu) {
            $existing = $this->refs->menuId((string) $menu['path']);

            if ($existing !== null) {
                $current = $this->exporter->menu(Menu::query()->findOrFail($existing));
                $items[] = new ImportItem(ImportItem::MENU, $menu['path'], $menu['path'], self::same($current, $menu) ? ImportItem::IDENTICAL : ImportItem::DIFFERENT);

                continue;
            }

            $target = (array) $menu['target'];
            $notes = array_values(array_filter([
                $menu['parent'] === null || in_array($menu['parent'], $newMenus, true) || $this->refs->menuId((string) $menu['parent']) !== null
                    ? null : __('configuration.notes.menu', ['menu' => $menu['parent']]),
                match ($target['type'] ?? 'group') {
                    'data' => $hasTable((string) ($target['table'] ?? '')) ? null : __('configuration.notes.table', ['table' => $target['table'] ?? '?']),
                    'report' => in_array($target['report'] ?? null, $newReports, true) || $this->refs->reportId((string) ($target['report'] ?? '')) !== null
                        ? null : __('configuration.notes.report', ['report' => $target['report'] ?? '?']),
                    'route' => Route::has((string) ($target['route'] ?? '')) ? null : __('configuration.notes.route', ['route' => $target['route'] ?? '?']),
                    default => null,
                },
            ]));
            $items[] = new ImportItem(ImportItem::MENU, $menu['path'], $menu['path'], $notes === [] ? ImportItem::NEW : ImportItem::MISSING, $notes);

            if ($notes === []) {
                $newMenus[] = (string) $menu['path'];
            }
        }

        foreach ($package['roles'] as $role) {
            $existing = Role::query()->where('name', $role['name'])->with(['permissions', 'hiddenMenus'])->first();

            if ($existing !== null) {
                $items[] = new ImportItem(ImportItem::ROLE, $role['name'], $role['name'], self::same($this->exporter->role($existing), $role) ? ImportItem::IDENTICAL : ImportItem::DIFFERENT);

                continue;
            }

            $tables = array_keys($newTables);
            $unknown = array_values(array_filter((array) $role['permissions'], fn (string $p): bool => ! $this->permissionAvailable($p, $tables)));
            $menus = array_values(array_filter((array) $role['hidden_menus'], fn (string $m): bool => ! in_array($m, $newMenus, true) && $this->refs->menuId($m) === null));
            $items[] = new ImportItem(ImportItem::ROLE, $role['name'], $role['name'], ImportItem::NEW, array_values(array_filter([
                $unknown === [] ? null : __('configuration.notes.permissions_skipped', ['permissions' => implode(', ', $unknown)]),
                $menus === [] ? null : __('configuration.notes.menus_skipped', ['menus' => implode(', ', $menus)]),
            ])));
        }

        return $items;
    }

    /**
     * Legt alle Einträge mit Zustand „neu“ an.
     *
     * @param  array<string, mixed>  $package
     * @return array<string, int> Bereich => Anzahl angelegt
     */
    public function apply(array $package, ?User $user): array
    {
        $new = [];

        foreach ($this->analyze($package) as $item) {
            if ($item->status === ImportItem::NEW) {
                $new[$item->area][] = $item->key;
            }
        }

        $created = array_fill_keys(ImportItem::AREAS, 0);

        // 1. Tabellen (DDL – auf MySQL/MariaDB nicht transaktional, deshalb vorher vollständig geprüft).
        $tables = array_values(array_filter($package['tables'], static fn (array $t): bool => in_array($t['name'], $new[ImportItem::TABLE] ?? [], true)));

        if ($tables !== []) {
            $created[ImportItem::TABLE] = count($this->schemaImporter->import(['format' => SchemaExporter::FORMAT, 'tables' => $tables]));
        }

        $this->refs->refresh();

        DB::transaction(function () use ($package, $new, $user, &$created): void {
            foreach ($package['report_links'] as $link) {
                if (in_array($link['source'].'>'.$link['target_table'], $new[ImportItem::REPORT_LINK] ?? [], true)) {
                    ReportLink::create([
                        'label' => $link['label'],
                        'source_table_id' => $this->refs->tableId((string) strstr((string) $link['source'], '.', true)),
                        'source_field_id' => $this->refs->fieldId((string) $link['source']),
                        'target_table_id' => $this->refs->tableId((string) $link['target_table']),
                        'target_field_id' => $link['target_field'] === null ? null : $this->refs->fieldId((string) $link['target_field']),
                    ]);
                    $created[ImportItem::REPORT_LINK]++;
                }
            }

            $this->refs->refresh();

            foreach ($package['reports'] as $report) {
                if (in_array($report['name'], $new[ImportItem::REPORT] ?? [], true)) {
                    Report::create([
                        'name' => $report['name'],
                        'description' => $report['description'] ?? null,
                        'owner_id' => $user?->id,
                        'is_shared' => (bool) ($report['is_shared'] ?? false),
                        'definition' => $this->reportDefinition((array) $report['definition']),
                    ]);
                    $created[ImportItem::REPORT]++;
                }
            }

            foreach ($package['menus'] as $menu) {
                if (in_array($menu['path'], $new[ImportItem::MENU] ?? [], true)) {
                    $this->refs->refresh();
                    Menu::create([
                        'parent_id' => $menu['parent'] === null ? null : $this->refs->menuId((string) $menu['parent']),
                        'label' => $menu['label'],
                        'icon' => $menu['icon'] ?? null,
                        'permission_name' => $menu['permission_name'] ?? null,
                        'sort_order' => (int) ($menu['sort_order'] ?? 0),
                        'is_active' => (bool) ($menu['is_active'] ?? true),
                        ...$this->menuTarget((array) $menu['target']),
                    ]);
                    $created[ImportItem::MENU]++;
                }
            }

            $this->refs->refresh();

            foreach ($package['roles'] as $role) {
                if (in_array($role['name'], $new[ImportItem::ROLE] ?? [], true)) {
                    $model = Role::create(['name' => $role['name'], 'guard_name' => 'web']);
                    $model->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', (array) $role['permissions'])->get());
                    $model->hiddenMenus()->sync(array_values(array_filter(array_map(fn (string $p): ?int => $this->refs->menuId($p), (array) $role['hidden_menus']))));
                    $created[ImportItem::ROLE]++;
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('configuration')
            ->causedBy($user)
            ->event('config_imported')
            ->withChanges(['attributes' => ['paket' => (string) ($package['label'] ?? ''), 'quelle' => (string) ($package['source'] ?? ''), ...$created]])
            ->log('config_imported');

        return $created;
    }

    /** @param  array<string, mixed>  $package */
    private function analyzeTable(array $table, array $package): ImportItem
    {
        $name = (string) $table['name'];
        $label = References::defaultLabel($table['label'] ?? []).' ('.$name.')';

        if ($this->refs->tableId($name) !== null) {
            $current = $this->schema->export([$name], true)['tables'][0] ?? [];

            return new ImportItem(ImportItem::TABLE, $name, $label, self::same($current, $table, ['permissions']) ? ImportItem::IDENTICAL : ImportItem::DIFFERENT);
        }

        $inPackage = array_column($package['tables'], 'name');
        $notes = [];

        foreach ((array) $table['fields'] as $field) {
            $target = (string) ($field['options']['table'] ?? '');

            if (($field['type'] ?? null) === 'relation' && ! in_array($target, $inPackage, true) && $this->refs->tableId($target) === null) {
                $notes[] = __('configuration.notes.table', ['table' => $target]);
            }
        }

        // Hinweis, keine fehlende Voraussetzung: Dateifelder kommen ohne Dateien (wie alle Datensätze).
        $files = array_values(array_map(static fn (array $f): string => (string) $f['name'], array_filter((array) $table['fields'], static fn (mixed $f): bool => is_array($f) && ($f['type'] ?? null) === 'file')));
        $hints = $files === [] ? [] : [__('configuration.notes.file_fields', ['fields' => implode(', ', $files)])];

        return new ImportItem(ImportItem::TABLE, $name, $label, $notes === [] ? ImportItem::NEW : ImportItem::MISSING, [...array_values(array_unique($notes)), ...$hints]);
    }

    /** @return array<string, mixed> */
    private function exporterLink(int $id): array
    {
        $link = ReportLink::query()->findOrFail($id);

        return [
            'label' => $link->getTranslations('label'),
            'source' => $this->refs->field((int) $link->source_field_id),
            'target_table' => $this->refs->table((int) $link->target_table_id),
            'target_field' => $link->target_field_id === null ? null : $this->refs->field((int) $link->target_field_id),
        ];
    }

    /**
     * Definition mit Namen → Definition mit IDs dieser Installation.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function reportDefinition(array $definition): array
    {
        $definition['base'] = $this->refs->tableId((string) ($definition['base'] ?? ''));
        $definition['fields'] = array_map(fn (mixed $f): mixed => is_array($f) ? [
            ...$f,
            'path' => array_map(fn (mixed $step): int => str_starts_with((string) $step, 'link:')
                ? -(int) $this->refs->linkId(substr((string) $step, 5))
                : (int) $this->refs->fieldId((string) $step), (array) ($f['path'] ?? [])),
            'field' => (int) $this->refs->fieldId((string) ($f['field'] ?? '')),
        ] : $f, (array) ($definition['fields'] ?? []));

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed> route, route_parameters, url, view_config
     */
    private function menuTarget(array $target): array
    {
        return match ($target['type'] ?? 'group') {
            'data' => ['route' => DataMenu::ROUTE, 'route_parameters' => ['table' => $target['table']], 'url' => null,
                'view_config' => ($columns = array_values(array_filter(array_map(fn (string $f): ?int => $this->refs->fieldId($f), (array) ($target['columns'] ?? []))))) === [] ? null : ['columns' => $columns]],
            'report' => ['route' => ReportResource::VIEW_ROUTE, 'route_parameters' => ['record' => (string) $this->refs->reportId((string) $target['report'])], 'url' => null, 'view_config' => null],
            'route' => ['route' => $target['route'], 'route_parameters' => array_map(
                fn (mixed $value): mixed => is_string($value) && str_starts_with($value, 'menu:') ? $this->refs->menuId(substr($value, 5)) : $value,
                (array) ($target['parameters'] ?? []),
            ) ?: null, 'url' => null, 'view_config' => null],
            'url' => ['route' => null, 'route_parameters' => null, 'url' => $target['url'], 'view_config' => null],
            default => ['route' => null, 'route_parameters' => null, 'url' => null, 'view_config' => null],
        };
    }

    /** Gibt es das Recht – oder entsteht es mit einer neuen Tabelle des Pakets (Datenrechte)? */
    private function permissionAvailable(string $permission, array $newTables): bool
    {
        if (Permission::query()->where('guard_name', 'web')->where('name', $permission)->exists()) {
            return true;
        }

        return preg_match('/^data\.([a-z0-9_]+)\./', $permission, $m) === 1 && in_array($m[1], $newTables, true);
    }

    /**
     * Gleich bis auf leere Angaben und Reihenfolge der Schlüssel.
     *
     * @param  array<mixed>  $a
     * @param  array<mixed>  $b
     * @param  list<string>  $ignore
     */
    private static function same(array $a, array $b, array $ignore = []): bool
    {
        foreach ($ignore as $key) {
            unset($a[$key], $b[$key]);
        }

        return self::normalize($a) === self::normalize($b);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function normalize(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $item = is_array($item) ? self::normalize($item) : $item;

            if ($item !== null && $item !== [] && $item !== '') {
                $result[$key] = $item;
            }
        }

        if (! array_is_list($value)) {
            ksort($result);
        } else {
            $result = array_values($result);
        }

        return $result;
    }
}
