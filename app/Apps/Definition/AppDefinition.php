<?php

declare(strict_types=1);

namespace App\Apps\Definition;

use App\Apps\PluginApps;
use App\Configuration\ConfigExporter;
use App\Configuration\References;
use App\Models\App;
use App\Models\AppManual;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Plugins\Api\ApiManifest;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\PluginRegistry;

/**
 * App-Definition „saft-app/1“ (B-107, Etappe 4): beschreibt eine App vollständig – Tabellen mit Feldern und Regeln,
 * Tabellenverknüpfungen, Auswertungen, Menüs, Rollen, Aufgaben, Handbuch, benötigte Plugins. Wird jederzeit aus der
 * Datenbank erzeugt (massgeblich ist die Datenbank, siehe docs/concept/APPS.md, Abschnitt 4).
 *
 * Jedes Objekt trägt seinen stabilen Schlüssel („key“, UUID); Verweise stehen zusätzlich als Namen (wie im
 * Konfigurationspaket, References), damit die Datei lesbar bleibt. Nie enthalten: Systemmenüs, Systemrollen,
 * System-Aufgaben, Zugangsdaten, Datensätze; angebundene Tabellen nur als Verweis.
 */
final readonly class AppDefinition
{
    public const string FORMAT = 'saft-app/1';

    /** Was neben Tabellen und Menüs (immer dabei) mitkommen kann. */
    public const array PARTS = ['reports', 'roles', 'tasks', 'manual'];

    public function __construct(
        private ConfigExporter $config,
        private SchemaExporter $schema,
        private References $refs,
        private PluginRegistry $plugins,
        private PluginApps $pluginApps,
    ) {}

    /**
     * @param  list<string>  $parts  Teile aus PARTS, die mitkommen
     * @param  User|null  $user  für Auswertungen: freigegebene und eigene (wie das Konfigurationspaket)
     * @return array<string, mixed>
     */
    public function build(App $app, array $parts = self::PARTS, ?User $user = null): array
    {
        $this->refs->refresh();
        $tables = CustomTable::query()->where('app_id', $app->id)->with('fields')->orderBy('name')->get();
        $designer = $tables->reject(static fn (CustomTable $t): bool => $t->isExternal());

        $definition = [
            'format' => self::FORMAT,
            'saft' => (string) config('saft.version'),
            'api' => ApiManifest::current(),
            'exported_at' => now()->toIso8601String(),
            'app' => [
                'key' => (string) $app->uuid,
                'name' => $app->getTranslations('name'),
                'description' => $app->getTranslations('description') ?: null,
                'icon' => $app->icon,
                'prefix' => $app->prefix,
                'version' => $app->version,
                'manual' => in_array('manual', $parts, true)
                    ? AppManual::query()->where('app_id', $app->id)->orderBy('locale')->pluck('content', 'locale')->all()
                    : [],
            ],
            'plugins' => [],
            'tables' => $designer->isEmpty() ? [] : $this->tables($designer->pluck('name')->all(), $designer->keyBy('name')->all()),
            'external_tables' => $tables->filter(static fn (CustomTable $t): bool => $t->isExternal())->map(static fn (CustomTable $t): array => [
                'key' => (string) $t->uuid,
                'name' => $t->name,
                'label' => $t->getTranslations('label'),
                'connection' => $t->databaseConnection?->name,
            ])->values()->all(),
            'report_links' => ReportLink::query()->where('app_id', $app->id)->orderBy('id')->get()
                ->map(fn (ReportLink $link): array => ['key' => (string) $link->uuid, ...$this->config->link($link)])->all(),
            'reports' => in_array('reports', $parts, true)
                ? ConfigExporter::exportableReports($user)->where('app_id', $app->id)->orderBy('name')->get()
                    ->map(fn (Report $report): array => ['key' => (string) $report->uuid, ...$this->config->report($report)])->all()
                : [],
            // Ohne Auswertungen fehlen auch die Menüeinträge auf Auswertungen (sie zeigten sonst ins Leere).
            'menus' => array_values(array_filter($this->menus($app), static fn (array $menu): bool => in_array('reports', $parts, true) || ($menu['target']['type'] ?? null) !== 'report')),
            'roles' => in_array('roles', $parts, true)
                ? Role::query()->where('app_id', $app->id)->with(['permissions', 'hiddenMenus'])->orderBy('name')->get()
                    ->map(fn (Role $role): array => ['key' => (string) $role->uuid, ...$this->config->role($role)])->all()
                : [],
            'tasks' => in_array('tasks', $parts, true)
                ? ScheduledTask::query()->where('app_id', $app->id)->where('is_system', false)->orderBy('id')->get()
                    ->map(static fn (ScheduledTask $task): array => [
                        'key' => (string) $task->uuid,
                        'name' => $task->name,
                        'type' => $task->type,
                        'parameters' => (array) ($task->parameters ?? []),
                        'frequency' => $task->frequency,
                        'interval_hours' => $task->interval_hours,
                        'at_time' => $task->at_time,
                        'weekday' => $task->weekday,
                        'is_active' => (bool) $task->is_active,
                    ])->all()
                : [],
        ];

        // Ausgeblendete Menüeinträge nur aus dieser App: Rollen tragen aus der Zeit vor den Apps oft Einstellungen für
        // Einträge anderer Apps mit, die nicht zur Definition gehören.
        $paths = array_column($definition['menus'], 'path');
        $definition['roles'] = array_map(static fn (array $role): array => [
            ...$role,
            'hidden_menus' => array_values(array_filter($role['hidden_menus'], static fn (string $path): bool => in_array($path, $paths, true))),
        ], $definition['roles']);

        $definition['plugins'] = $this->requiredPlugins($app, $definition);

        return $definition;
    }

    /** Dateiname für den Download, z. B. „lager-1.2.saft-app.json“. */
    public static function fileName(App $app): string
    {
        $base = filled($app->prefix) ? rtrim((string) $app->prefix, '_') : str((string) $app->name)->slug()->toString();

        return ($base !== '' ? $base : 'app').(filled($app->version) ? '-'.str((string) $app->version)->slug('.')->toString() : '').'.saft-app.json';
    }

    /**
     * Tabellen wie das Schema für Plugins (Eingabeformat des Table-Designers), ergänzt um die stabilen Schlüssel.
     *
     * @param  list<string>  $names
     * @param  array<string, CustomTable>  $models
     * @return list<array<string, mixed>>
     */
    private function tables(array $names, array $models): array
    {
        return array_map(static function (array $table) use ($models): array {
            $model = $models[$table['name']];
            $keys = $model->fields->mapWithKeys(static fn (CustomField $f): array => [$f->name => (string) $f->uuid]);
            // Rechte ergeben sich beim Anlegen aus dem Namen; in der Definition nicht nötig.
            unset($table['permissions']);

            return [
                'key' => (string) $model->uuid,
                ...$table,
                'fields' => array_map(static fn (array $field): array => ['key' => $keys[$field['name']] ?? null, ...$field], $table['fields']),
            ];
        }, $this->schema->export($names, false)['tables']);
    }

    /**
     * Menüs der App, Eltern vor Kindern (Systemmenüs gehören zu keiner App und fehlen damit).
     *
     * @return list<array<string, mixed>>
     */
    private function menus(App $app): array
    {
        $rows = Menu::query()->where('app_id', $app->id)->get()
            ->map(fn (Menu $menu): array => ['key' => (string) $menu->uuid, ...$this->config->menu($menu)])->all();
        usort($rows, static fn (array $a, array $b): int => [substr_count($a['path'], ' › '), $a['sort_order'], $a['path']] <=> [substr_count($b['path'], ' › '), $b['sort_order'], $b['path']]);

        return $rows;
    }

    /**
     * Plugins, die die App braucht: aus Rechten, Menüs, Prüfregeln und Formeln (wie das Konfigurationspaket) und die
     * Plugins der App selbst (PluginApps).
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string> Kennung => installierte Version
     */
    private function requiredPlugins(App $app, array $definition): array
    {
        $required = $this->config->requiredPlugins($definition);

        foreach ($this->plugins->all() as $id => $plugin) {
            if ($this->pluginApps->appIdFor($plugin) === $app->id) {
                $required[$id] = $plugin->version();
            }
        }

        ksort($required);

        return $required;
    }
}
