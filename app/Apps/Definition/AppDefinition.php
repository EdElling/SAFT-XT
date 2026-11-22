<?php

declare(strict_types=1);

namespace App\Apps\Definition;

use App\Apps\AppInterfaces;
use App\Apps\PluginApps;
use App\DynamicData\DataPermissions;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Schema\FieldType;
use App\Models\App;
use App\Models\AppManual;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DocumentTemplate;
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
 *
 * Schnittstellen (Etappe 5b): Freigaben stehen bei Tabellen und Feldern („is_shared“), die Version in
 * „app.interface_version“. Was die App von anderen Apps nutzt, steht in „requires“ (App, Version, Tabellen, Felder);
 * Installieren setzt diese Freigaben voraus.
 */
final readonly class AppDefinition
{
    public const string FORMAT = 'saft-app/1';

    /** Was neben Tabellen und Menüs (immer dabei) mitkommen kann. */
    public const array PARTS = ['reports', 'roles', 'tasks', 'documents', 'manual'];

    public function __construct(
        private DefinitionParts $config,
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
            // Enthaltene Teile: beim Aktualisieren (Etappe 6) heisst ein fehlender Teil „nicht mitgeliefert“, nicht „leer“.
            'parts' => array_values(array_intersect(self::PARTS, $parts)),
            'app' => [
                'key' => (string) $app->uuid,
                'name' => $app->getTranslations('name'),
                'description' => $app->getTranslations('description') ?: null,
                'icon' => $app->icon,
                'prefix' => $app->prefix,
                'version' => $app->version,
                'interface_version' => (int) $app->interface_version,
                'manual' => in_array('manual', $parts, true)
                    ? AppManual::query()->where('app_id', $app->id)->orderBy('locale')->pluck('content', 'locale')->all()
                    : [],
            ],
            'plugins' => [],
            'tables' => $designer->isEmpty() ? [] : $this->tables($designer->pluck('name')->all(), $designer->keyBy('name')->all()),
            'external_tables' => $tables->filter(static fn (CustomTable $t): bool => $t->isExternal())->map(fn (CustomTable $t): array => $this->external($t))->values()->all(),
            'report_links' => ReportLink::query()->where('app_id', $app->id)->orderBy('id')->get()
                ->map(fn (ReportLink $link): array => ['key' => (string) $link->uuid, ...$this->config->link($link)])->all(),
            'reports' => in_array('reports', $parts, true)
                ? DefinitionParts::exportableReports($user)->where('app_id', $app->id)->orderBy('name')->get()
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
                        'parameters' => self::taskParameters((array) ($task->parameters ?? [])),
                        'frequency' => $task->frequency,
                        'interval_hours' => $task->interval_hours,
                        'at_time' => $task->at_time,
                        'weekday' => $task->weekday,
                        'is_active' => (bool) $task->is_active,
                    ])->all()
                : [],
            // Dokumentvorlagen (B-124, D1d): ohne Briefpapier – das ist das CI der jeweiligen Installation.
            'documents' => in_array('documents', $parts, true)
                ? DocumentTemplate::query()->where('app_id', $app->id)->with('customTable')->orderBy('sort_order')->orderBy('name')->get()
                    ->map(static fn (DocumentTemplate $template): array => self::documentTemplate($template))->all()
                : [],
        ];

        // Ausgeblendete Menüeinträge nur aus dieser App: Rollen tragen aus der Zeit vor den Apps oft Einstellungen für
        // Einträge anderer Apps mit, die nicht zur Definition gehören.
        $paths = array_column($definition['menus'], 'path');
        $definition['roles'] = array_map(static fn (array $role): array => [
            ...$role,
            'hidden_menus' => array_values(array_filter($role['hidden_menus'], static fn (string $path): bool => in_array($path, $paths, true))),
        ], $definition['roles']);

        $definition['roles'] = array_map(fn (array $role): array => [...$role, 'permissions' => $this->rolePermissions($app, $role['permissions'])], $definition['roles']);
        $definition['requires'] = $this->requires($definition);
        $definition['plugins'] = $this->requiredPlugins($app, $definition);

        return $definition;
    }

    /** Felder einer angebundenen Tabelle, wie sie der Table-Connector anlegt (ohne interne IDs). */
    /**
     * Dokumentvorlage in der Definition; die Grundtabelle steht als Name.
     *
     * @return array<string, mixed>
     */
    public static function documentTemplate(DocumentTemplate $template): array
    {
        return [
            'key' => (string) $template->uuid,
            'name' => $template->name,
            'table' => (string) $template->customTable?->name,
            'recipient' => $template->recipient,
            'body' => $template->body,
            'file_name' => $template->file_name,
            'language_field' => $template->language_field,
            'file_field' => $template->file_field,
            'email_to' => $template->email_to,
            'email_subject' => $template->email_subject,
            'email_body' => $template->email_body,
            'email_reply_to' => $template->email_reply_to,
            'qr_bill' => $template->qr_bill,
            'is_active' => (bool) $template->is_active,
            'sort_order' => (int) $template->sort_order,
        ];
    }

    private const array EXTERNAL_FIELD_KEYS = ['type', 'external_type', 'is_read_only', 'is_generated', 'is_required', 'is_unique', 'is_searchable', 'is_sortable', 'show_in_table', 'show_in_form', 'is_active', 'sort_order'];

    /**
     * Angebundene Tabelle mit Quelle und Spalten, damit sie sich ohne erreichbare Datenbank anlegen lässt; Name der
     * Verbindung als Verweis, nie ihre Zugangsdaten.
     *
     * @return array<string, mixed>
     */
    private function external(CustomTable $table): array
    {
        return [
            'key' => (string) $table->uuid,
            'name' => $table->name,
            'label' => $table->getTranslations('label'),
            'description' => $table->getTranslations('description') ?: null,
            'icon' => $table->icon,
            'connection' => $table->databaseConnection?->name,
            'source' => ['schema' => $table->external_schema, 'table' => $table->external_table, 'kind' => $table->external_kind],
            'key_columns' => $table->keyColumns(),
            'allow' => ['create' => (bool) $table->allow_create, 'update' => (bool) $table->allow_update, 'delete' => (bool) $table->allow_delete],
            'export_enabled' => (bool) $table->export_enabled,
            'fields' => $table->fields->sortBy('sort_order')->values()->map(static fn (CustomField $f): array => [
                'key' => (string) $f->uuid,
                'name' => $f->name,
                'label' => $f->getTranslations('label'),
                ...array_map(static fn (mixed $v): mixed => $v instanceof \BackedEnum ? $v->value : $v, $f->only(self::EXTERNAL_FIELD_KEYS)),
                'options' => $f->optionsArray() ?: null,
                'validation_rules' => (array) ($f->validation_rules ?? []) ?: null,
            ])->all(),
        ];
    }

    /**
     * Interne IDs in Parametern geplanter Aufgaben als Namen (gelten so auch in einer anderen Installation).
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function taskParameters(array $parameters): array
    {
        if (isset($parameters['table_id'])) {
            $parameters['table'] = CustomTable::query()->whereKey($parameters['table_id'])->value('name');
            unset($parameters['table_id']);
        }

        if (isset($parameters['connection_id'])) {
            $parameters['connection'] = DatabaseConnection::query()->whereKey($parameters['connection_id'])->value('name');
            unset($parameters['connection_id']);
        }

        return $parameters;
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

            $shared = $model->fields->filter(static fn (CustomField $f): bool => $f->is_shared)->pluck('name')->all();

            return [
                'key' => (string) $model->uuid,
                ...$table,
                // Schnittstelle (Etappe 5b): nur bei freigegebenen Tabellen bzw. Feldern.
                ...($model->is_shared ? ['is_shared' => true] : []),
                'fields' => array_map(static fn (array $field): array => [
                    'key' => $keys[$field['name']] ?? null,
                    ...$field,
                    ...(in_array($field['name'], $shared, true) ? ['is_shared' => true] : []),
                ], $table['fields']),
            ];
        }, $this->schema->export($names, false)['tables']);
    }

    /**
     * Rechte einer Rolle, die zur App gehören: alle ausser Datenrechten auf Tabellen anderer Apps – von dort nur
     * Anzeigen auf freigegebene Tabellen (Schnittstelle); Rechte „ausserhalb“ aus der Zeit vor den Apps gehören nicht dazu.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function rolePermissions(App $app, array $permissions): array
    {
        $tables = CustomTable::query()->get(['name', 'app_id', 'is_shared'])->keyBy('name');

        return array_values(array_filter($permissions, static function (string $permission) use ($app, $tables): bool {
            if (! str_starts_with($permission, DataPermissions::PREFIX)) {
                return true;
            }

            $rest = substr($permission, strlen(DataPermissions::PREFIX));
            $table = $tables->get(substr($rest, 0, (int) strrpos($rest, '.')));

            return $table === null || ! AppInterfaces::foreign($table, $app->id)
                || ($table->is_shared && str_ends_with($rest, '.view'));
        }));
    }

    /**
     * Was die App von anderen Apps nutzt (Schnittstellen): Verknüpfungsfelder mit Anzeige- und Übernahmefeldern,
     * Tabellenverknüpfungen, Felder in Auswertungen und Leserechte von Rollen – gruppiert nach App.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{app: array<string, mixed>, tables: list<array{key: string, name: string, fields: list<string>}>}>
     */
    private function requires(array $definition): array
    {
        $own = [...array_column($definition['tables'], 'name'), ...array_column($definition['external_tables'], 'name')];
        $need = [];
        $use = static function (string $table, array $fields = []) use (&$need, $own): void {
            if ($table !== '' && ! in_array($table, $own, true)) {
                $need[$table] = [...($need[$table] ?? []), ...array_filter($fields, static fn (string $f): bool => $f !== '' && $f !== 'id')];
            }
        };

        foreach ([...$definition['tables'], ...$definition['external_tables']] as $table) {
            $targets = [];

            foreach ((array) $table['fields'] as $field) {
                $options = (array) ($field['options'] ?? []);

                if (($field['type'] ?? null) === FieldType::Relation->value) {
                    $targets[$field['name']] = (string) ($options['table'] ?? '');
                    $use($targets[$field['name']], array_map('strval', (array) ($options['display_fields'] ?? array_filter([$options['display_field'] ?? null]))));
                }
            }

            foreach ((array) $table['fields'] as $field) {
                $copy = (string) (((array) ($field['options'] ?? []))[CopySource::OPTION] ?? '');

                if (str_contains($copy, '.') && isset($targets[strstr($copy, '.', true)])) {
                    $use($targets[strstr($copy, '.', true)], [substr($copy, strpos($copy, '.') + 1)]);
                }
            }
        }

        foreach ($definition['report_links'] as $link) {
            $use((string) $link['target_table'], [(string) substr((string) ($link['target_field'] ?? ''), strpos((string) ($link['target_field'] ?? ''), '.') + 1)]);
        }

        foreach ($definition['reports'] as $report) {
            foreach ((array) (($report['definition'] ?? [])['fields'] ?? []) as $field) {
                $ref = (string) (is_array($field) ? ($field['field'] ?? '') : '');

                if (str_contains($ref, '.')) {
                    $use((string) strstr($ref, '.', true), [substr($ref, strpos($ref, '.') + 1)]);
                }
            }
        }

        foreach ($definition['roles'] as $role) {
            foreach ((array) $role['permissions'] as $permission) {
                if (str_starts_with((string) $permission, DataPermissions::PREFIX)) {
                    $rest = substr((string) $permission, strlen(DataPermissions::PREFIX));
                    $use(substr($rest, 0, (int) strrpos($rest, '.')));
                }
            }
        }

        $apps = [];

        foreach (CustomTable::query()->whereIn('name', array_keys($need))->with('app')->orderBy('name')->get() as $table) {
            $owner = $table->app;
            $key = (string) ($owner?->uuid ?? '');
            $apps[$key] ??= ['app' => [
                'key' => $key,
                'name' => $owner?->getTranslations('name') ?? [],
                'prefix' => $owner?->prefix,
                'interface_version' => (int) ($owner?->interface_version ?? 1),
            ], 'tables' => []];
            $fields = array_values(array_unique($need[$table->name]));
            sort($fields);
            $apps[$key]['tables'][] = ['key' => (string) $table->uuid, 'name' => $table->name, 'fields' => $fields];
        }

        return array_values($apps);
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
