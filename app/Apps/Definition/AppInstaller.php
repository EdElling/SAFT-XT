<?php

declare(strict_types=1);

namespace App\Apps\Definition;

use App\Apps\AppInterfaces;
use App\Documents\Templates\TemplateChecker;
use App\DynamicData\DataPermissions;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\Parser;
use App\DynamicData\Formula\Printer;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Models\App;
use App\Models\AppBaseline;
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
use App\Plugins\Context\SchemaExporter;
use App\Plugins\Context\SchemaImporter;
use App\Plugins\PluginRegistry;
use App\Tasks\TaskRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Installiert eine App-Definition „saft-app/1“ als neue App (B-107, Etappe 4b) – unverändert mit ihren stabilen
 * Schlüsseln oder als Kopie (neuer Name, neues Präfix, neue Schlüssel). Die App entsteht deaktiviert, damit man sie vor
 * der Freigabe prüfen kann. Tabellen über den Table-Designer (SchemaImporter, mit seinen Schutzregeln); angebundene
 * Tabellen inaktiv, mit der gleichnamigen Verbindung (und Abgleich) oder ohne Verbindung. Verweise zwischen den Objekten
 * werden innerhalb der neuen App aufgelöst, nicht über die ganze Installation (eine Kopie hat dieselben Menüpfade und
 * Namen von Auswertungen wie das Original). Zuletzt wird der Ausgangsstand gespeichert.
 */
final class AppInstaller
{
    /** Höchstgrösse einer Definitionsdatei. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> Hinweise beim Anlegen (z. B. Abgleich angebundener Tabellen) */
    private array $notes = [];

    public function __construct(
        private readonly AppDefinitionValidator $validator,
        private readonly SchemaImporter $schemaImporter,
        private readonly References $refs,
        private readonly PluginRegistry $plugins,
        private readonly TaskRegistry $tasks,
        private readonly Parser $parser,
        private readonly DefinitionObjects $objects,
    ) {}

    /**
     * Datei lesen.
     *
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    public function read(string $path): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new BusinessRuleException(__('apps.install.errors.file'));
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new BusinessRuleException(__('apps.install.errors.json'));
        }

        return $data;
    }

    /**
     * Prüfen, was angelegt würde und was es verhindert.
     *
     * @param  array<string, mixed>  $definition
     * @param  array{name: string, prefix: string}|null  $copy  als Kopie: Name und Präfix der neuen App
     */
    public function analyze(array $definition, ?array $copy = null): InstallAnalysis
    {
        // Definitionen vor D1d (B-124) haben keine Dokumentvorlagen.
        $definition['documents'] = array_values((array) ($definition['documents'] ?? []));
        $blockers = array_slice($this->validator->errors($definition), 0, 20);

        if ($blockers !== []) {
            return new InstallAnalysis($definition, $blockers, [], [], $copy !== null);
        }

        $warnings = [];

        if ($copy !== null) {
            $prefix = strtolower(rtrim(trim($copy['prefix']), '_'));

            if (blank(trim($copy['name']))) {
                $blockers[] = __('apps.install.errors.copy_name');
            }

            if (preg_match('/^[a-z][a-z0-9]{0,19}$/', $prefix) !== 1) {
                $blockers[] = __('apps.install.errors.copy_prefix');
            } elseif ($prefix === strtolower(rtrim((string) ($definition['app']['prefix'] ?? ''), '_'))) {
                $blockers[] = __('apps.install.errors.copy_same_prefix');
            }

            if ($blockers !== []) {
                return new InstallAnalysis($definition, $blockers, [], [], true);
            }

            $definition = $this->asCopy($definition, trim($copy['name']), $prefix);
        } elseif (($existing = App::query()->where('uuid', $definition['app']['key'])->first()) !== null) {
            $blockers[] = __('apps.install.errors.installed', ['app' => (string) $existing->name]);
        }

        // Namen sind installationsweit eindeutig.
        foreach ([...$definition['tables'], ...$definition['external_tables']] as $table) {
            if (($owner = CustomTable::query()->where('name', $table['name'])->first()) !== null) {
                $blockers[] = __('apps.install.errors.table_exists', ['table' => $table['name'], 'app' => (string) ($owner->app?->name ?? __('apps.system'))]);
            }

            if (strlen((string) $table['name']) > 63) {
                $blockers[] = __('apps.install.errors.table_name_long', ['table' => $table['name']]);
            }
        }

        [$requires, $missing, $notes] = $this->requirements((array) ($definition['requires'] ?? []));
        $blockers = [...$blockers, ...$missing];
        $warnings = [...$warnings, ...$notes];

        foreach (array_keys((array) ($definition['plugins'] ?? [])) as $plugin) {
            if (! $this->plugins->has((string) $plugin)) {
                $warnings[] = __('apps.install.warnings.plugin', ['plugin' => (string) $plugin]);
            }
        }

        foreach ($definition['external_tables'] as $table) {
            $connection = DatabaseConnection::query()->where('name', (string) ($table['connection'] ?? ''))->first();
            $warnings[] = $connection === null
                ? __('apps.install.warnings.external_unconnected', ['table' => $table['name'], 'connection' => (string) ($table['connection'] ?? '–')])
                : __('apps.install.warnings.external_connected', ['table' => $table['name'], 'connection' => $connection->name]);
        }

        foreach ($definition['roles'] as $role) {
            if (Role::query()->where('name', $role['name'])->exists()) {
                $warnings[] = __('apps.install.warnings.role', ['role' => $role['name'], 'new' => $this->roleName((string) $role['name'], (string) ($definition['app']['prefix'] ?? ''))]);
            }
        }

        foreach ($definition['tasks'] as $task) {
            if (! $this->tasks->has((string) $task['type'])) {
                $warnings[] = __('apps.install.warnings.task_type', ['task' => (string) ($task['name'] ?? $task['type'])]);
            }
        }

        $files = collect($definition['tables'])->flatMap(static fn (array $t): array => array_map(static fn (array $f): string => $t['name'].'.'.$f['name'], array_filter($t['fields'], static fn (array $f): bool => ($f['type'] ?? null) === 'file')))->all();

        if ($files !== []) {
            $warnings[] = __('apps.install.warnings.files', ['fields' => implode(', ', $files)]);
        }

        return new InstallAnalysis($definition, $blockers, $warnings, [
            'tables' => count($definition['tables']),
            'fields' => array_sum(array_map(static fn (array $t): int => count($t['fields']), $definition['tables'])),
            'external_tables' => count($definition['external_tables']),
            'report_links' => count($definition['report_links']),
            'reports' => count($definition['reports']),
            'menus' => count($definition['menus']),
            'roles' => count($definition['roles']),
            'tasks' => count($definition['tasks']),
            'documents' => count($definition['documents']),
            'manuals' => count((array) ($definition['app']['manual'] ?? [])),
        ], $copy !== null, $requires);
    }

    /**
     * Benötigte Schnittstellen anderer Apps (Etappe 5b): Jede Tabelle muss vorhanden und freigegeben sein, mit allen
     * genutzten Feldern. Sonst ein Hinderungsgrund; deaktivierte App oder andere Version nur als Hinweis.
     *
     * @param  array<int, mixed>  $requires
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} Übersicht, Hinderungsgründe, Hinweise
     */
    public function requirements(array $requires): array
    {
        $overview = [];
        $blockers = [];
        $warnings = [];

        foreach ($requires as $entry) {
            $app = (array) ($entry['app'] ?? []);
            $appName = References::defaultLabel($app['name'] ?? []) ?: (string) ($app['prefix'] ?? '–');
            $installed = null;

            foreach ((array) ($entry['tables'] ?? []) as $required) {
                $name = (string) ($required['name'] ?? '');
                $fields = array_values(array_map('strval', (array) ($required['fields'] ?? [])));
                $table = CustomTable::query()->where('name', $name)->with(['fields', 'app'])->first();
                $overview[] = __('apps.install.requires_table', ['app' => $appName, 'table' => $name, 'fields' => $fields === [] ? '–' : implode(', ', $fields)]);

                if ($table === null || $table->app_id === null) {
                    $blockers[] = __('apps.install.errors.requires_missing', ['app' => $appName, 'table' => $name]);

                    continue;
                }

                $installed ??= $table->app;

                if (! $table->is_shared) {
                    $blockers[] = __('apps.install.errors.requires_not_shared', ['app' => (string) $table->app?->name, 'table' => $name]);

                    continue;
                }

                $unshared = array_values(array_filter($fields, static fn (string $f): bool => ! AppInterfaces::fieldShared($table, $f)));

                if ($unshared !== []) {
                    $blockers[] = __('apps.install.errors.requires_fields', ['app' => (string) $table->app?->name, 'table' => $name, 'fields' => implode(', ', $unshared)]);
                }
            }

            if ($installed !== null && ! $installed->is_active) {
                $warnings[] = __('apps.install.warnings.requires_inactive', ['app' => (string) $installed->name]);
            }

            if ($installed !== null && isset($app['interface_version']) && (int) $app['interface_version'] !== (int) $installed->interface_version) {
                $warnings[] = __('apps.install.warnings.requires_version', ['app' => (string) $installed->name, 'expected' => (int) $app['interface_version'], 'actual' => (int) $installed->interface_version]);
            }
        }

        return [$overview, array_values(array_unique($blockers)), $warnings];
    }

    /**
     * Installieren (nach analyze ohne Hindernisse). Die App ist danach deaktiviert.
     *
     * @param  array{name: string, prefix: string}|null  $copy
     *
     * @throws BusinessRuleException
     */
    public function install(array $definition, ?array $copy, User $user): App
    {
        $analysis = $this->analyze($definition, $copy);

        if (! $analysis->canInstall()) {
            throw new BusinessRuleException(implode(' ', $analysis->blockers));
        }

        $definition = $analysis->definition;
        $this->notes = [];
        $data = $definition['app'];

        $app = App::query()->create([
            'uuid' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'prefix' => $data['prefix'] ?? null,
            'version' => $data['version'] ?? null,
            'interface_version' => max(1, (int) ($data['interface_version'] ?? 1)),
            'sort_order' => ((int) App::query()->max('sort_order')) + 10,
            'is_active' => false,
        ]);

        try {
            // 1. Angebundene Tabellen zuerst: Verknüpfungen eigener Tabellen dürfen auf sie zeigen.
            foreach ($definition['external_tables'] as $table) {
                $this->external($app, $table);
            }

            // 2. Tabellen des Table-Designers (DDL; auf MySQL/MariaDB nicht transaktional, deshalb vorher geprüft).
            if ($definition['tables'] !== []) {
                $tables = array_map(static fn (array $t): array => [...Arr::except($t, ['is_shared']), 'fields' => array_map(static function (array $f): array {
                    unset($f['key'], $f['is_shared']);

                    return $f;
                }, $t['fields'])], $definition['tables']);
                $this->schemaImporter->import(['format' => SchemaExporter::FORMAT, 'tables' => $tables], app: $app);

                foreach ($definition['tables'] as $table) {
                    $model = CustomTable::query()->where('name', $table['name'])->firstOrFail();
                    $model->forceFill(['uuid' => $table['key'], 'is_shared' => (bool) ($table['is_shared'] ?? false)])->saveQuietly();

                    foreach ($table['fields'] as $field) {
                        CustomField::query()->where('custom_table_id', $model->id)->where('name', $field['name'])
                            ->update(['uuid' => $field['key'], 'is_shared' => (bool) ($field['is_shared'] ?? false)]);
                    }
                }
            }

            $this->refs->refresh();

            // 3. Alles Weitere in einer Transaktion.
            DB::transaction(fn () => $this->objects($app, $definition, $user));
        } catch (Throwable $e) {
            throw new BusinessRuleException(__('apps.install.errors.partial', ['app' => (string) $app->name, 'error' => $e->getMessage()]), previous: $e);
        }

        AppBaseline::query()->create([
            'app_id' => $app->id,
            'kind' => $copy === null ? AppBaseline::INSTALLED : AppBaseline::COPY,
            'version' => $data['version'] ?? null,
            'definition' => $definition,
            'installed_by' => $user->id,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('apps')->causedBy($user)->performedOn($app)->event($copy === null ? 'installed' : 'installed_copy')
            ->withProperties(['version' => $data['version'] ?? null, 'counts' => $analysis->counts])
            ->log(__('apps.install.log', ['name' => (string) $app->name]));

        return $app;
    }

    /** @return list<string> Hinweise des letzten install() (z. B. Abgleich angebundener Tabellen) */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return list<string> Hinweise seit dem letzten Abholen – für das Aktualisieren (ChangeExecutor) */
    public function takeNotes(): array
    {
        [$notes, $this->notes] = [$this->notes, []];

        return $notes;
    }

    /** @param  array<string, mixed>  $definition */
    private function objects(App $app, array $definition, User $user): void
    {
        foreach ($definition['report_links'] as $link) {
            ReportLink::query()->create(['uuid' => $link['key'], ...$this->objects->link($link)]);
        }

        $this->refs->refresh();
        $reports = [];

        foreach ($definition['reports'] as $report) {
            $reports[$report['name']] ??= (int) Report::query()->create([
                'uuid' => $report['key'],
                'name' => $report['name'],
                'description' => $report['description'] ?? null,
                'owner_id' => $user->id,
                'is_shared' => (bool) ($report['is_shared'] ?? false),
                'definition' => $this->objects->reportDefinition((array) $report['definition']),
            ])->id;
        }

        // Menüs: Pfade nur innerhalb der neuen App auflösen; eine Elterngruppe ausserhalb der App über ihren Pfad.
        $menus = [];

        foreach ($definition['menus'] as $menu) {
            $parent = $this->objects->menuParent($menu['parent'] === null ? null : (string) $menu['parent'], $menus);
            $menus[$menu['path']] = (int) Menu::query()->create([
                'uuid' => $menu['key'],
                'app_id' => $app->id,
                'parent_id' => $parent,
                'label' => $menu['label'],
                'icon' => $menu['icon'] ?? null,
                'permission_name' => $menu['permission_name'] ?? null,
                'sort_order' => (int) ($menu['sort_order'] ?? 0),
                'is_active' => (bool) ($menu['is_active'] ?? true),
                ...$this->objects->menuTarget((array) $menu['target'], $reports, $menus),
            ])->id;
        }

        foreach ($definition['roles'] as $role) {
            $model = Role::query()->create(['uuid' => $role['key'], 'name' => $this->roleName((string) $role['name'], (string) ($app->prefix ?? '')), 'guard_name' => 'web', 'app_id' => $app->id]);
            $model->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', (array) $role['permissions'])->get());
            $model->hiddenMenus()->sync(array_values(array_filter(array_map(static fn (string $path): ?int => $menus[$path] ?? null, (array) $role['hidden_menus']))));
        }

        foreach ($definition['tasks'] as $task) {
            if (! $this->tasks->has((string) $task['type']) || ($parameters = $this->taskParameters((array) $task['parameters'])) === null) {
                continue;
            }

            ScheduledTask::query()->create([
                'uuid' => $task['key'],
                'app_id' => $app->id,
                'name' => $task['name'] ?? null,
                'type' => $task['type'],
                'parameters' => $parameters,
                'frequency' => $task['frequency'],
                'interval_hours' => $task['interval_hours'] ?? null,
                'at_time' => $task['at_time'] ?? '03:00',
                'weekday' => $task['weekday'] ?? null,
                'is_active' => (bool) ($task['is_active'] ?? true),
                'created_by' => $user->id,
            ]);
        }

        foreach ((array) ($definition['app']['manual'] ?? []) as $locale => $content) {
            AppManual::query()->create(['app_id' => $app->id, 'locale' => (string) $locale, 'content' => (string) $content]);
        }

        foreach ($definition['documents'] as $document) {
            $this->createDocumentTemplate($app, (array) $document);
        }
    }

    /**
     * Dokumentvorlage anlegen (B-124, D1d) und prüfen: Fehler der Vorlage verhindern die Installation nicht, sie stehen
     * als Hinweis in notes(). Auch für das Aktualisieren (ChangeExecutor).
     *
     * @param  array<string, mixed>  $document
     */
    public function createDocumentTemplate(App $app, array $document): DocumentTemplate
    {
        $table = CustomTable::query()->where('name', (string) $document['table'])->firstOrFail();
        $template = DocumentTemplate::query()->create([
            'uuid' => $document['key'],
            'app_id' => $app->id,
            'custom_table_id' => $table->id,
            ...self::documentAttributes($document),
        ]);
        $this->checkDocumentTemplate($template, $table);

        return $template;
    }

    /**
     * Eigenschaften einer Vorlage aus der Definition (ohne Schlüssel, App und Tabelle).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function documentAttributes(array $document): array
    {
        return [
            'name' => (string) $document['name'],
            'recipient' => $document['recipient'] ?? null,
            'body' => (string) $document['body'],
            'file_name' => $document['file_name'] ?? null,
            'language_field' => $document['language_field'] ?? null,
            'file_field' => $document['file_field'] ?? null,
            'is_active' => (bool) ($document['is_active'] ?? true),
            'sort_order' => (int) ($document['sort_order'] ?? 0),
        ];
    }

    /** Vorlage prüfen; Fehler als Hinweis in notes(). */
    public function checkDocumentTemplate(DocumentTemplate $template, CustomTable $table): void
    {
        $errors = app(TemplateChecker::class)->check($table, (string) $template->body, $template->recipient, $template->file_name)['errors'];

        if ($errors !== []) {
            $this->notes[] = __('apps.install.warnings.document', ['name' => $template->name, 'errors' => implode(' · ', array_slice($errors, 0, 3))]);
        }
    }

    /**
     * Angebundene Tabelle ohne Zugriff auf die externe Datenbank anlegen – inaktiv. Gibt es eine Verbindung gleichen
     * Namens, wird sie zugeordnet und die Spalten werden mit der Datenbank abgeglichen (soweit erreichbar).
     *
     * @param  array<string, mixed>  $data
     */
    public function external(App $app, array $data): void
    {
        $connection = DatabaseConnection::query()->where('name', (string) ($data['connection'] ?? ''))->first();

        $table = DB::transaction(static function () use ($app, $data, $connection): CustomTable {
            $table = CustomTable::query()->create([
                'uuid' => $data['key'],
                'app_id' => $app->id,
                'name' => $data['name'],
                'source' => CustomTable::SOURCE_EXTERNAL,
                'database_connection_id' => $connection?->id,
                'external_schema' => $data['source']['schema'] ?? null,
                'external_table' => $data['source']['table'],
                'external_kind' => $data['source']['kind'] ?? null,
                'key_columns' => (array) ($data['key_columns'] ?? []),
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? null,
                'is_active' => false,
                'export_enabled' => (bool) ($data['export_enabled'] ?? false),
                'allow_create' => (bool) ($data['allow']['create'] ?? false),
                'allow_update' => (bool) ($data['allow']['update'] ?? false),
                'allow_delete' => (bool) ($data['allow']['delete'] ?? false),
            ]);

            foreach ((array) $data['fields'] as $field) {
                $key = $field['key'];
                unset($field['key']);
                $table->fields()->create([...$field, 'uuid' => $key, 'options' => $field['options'] ?? [], 'validation_rules' => $field['validation_rules'] ?? null]);
            }

            app(DataPermissions::class)->ensure($table);

            return $table;
        });

        if ($connection === null) {
            return;
        }

        try {
            foreach (app(TableConnectorService::class)->refresh($table) as $notice) {
                $this->notes[] = $table->name.': '.$notice;
            }
        } catch (Throwable $e) {
            $this->notes[] = __('apps.install.warnings.external_check_failed', ['table' => $table->name, 'error' => $e->getMessage()]);
        }
    }

    /** Vergebener Rollenname: mit dem Präfix der App (wie beim Verschieben), sonst durchnummeriert. */
    public function roleName(string $name, string $prefix): string
    {
        if (! Role::query()->where('name', $name)->exists()) {
            return $name;
        }

        $base = $name.'-'.(rtrim($prefix, '_') !== '' ? rtrim($prefix, '_') : 'app');

        for ($candidate = $base, $i = 2; Role::query()->where('name', $candidate)->exists(); $i++) {
            $candidate = $base.'-'.$i;
        }

        return $candidate;
    }

    /**
     * Namen in Parametern geplanter Aufgaben → IDs dieser Installation; null, wenn das Ziel fehlt.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|null
     */
    public function taskParameters(array $parameters): ?array
    {
        if (array_key_exists('table', $parameters)) {
            $id = $this->refs->tableId((string) $parameters['table']);

            if ($id === null) {
                return null;
            }

            $parameters['table_id'] = $id;
            unset($parameters['table']);
        }

        if (array_key_exists('connection', $parameters)) {
            $id = DatabaseConnection::query()->where('name', (string) $parameters['connection'])->value('id');

            if ($id === null) {
                return null;
            }

            $parameters['connection_id'] = (int) $id;
            unset($parameters['connection']);
        }

        return $parameters;
    }

    /**
     * Kopie: neuer Name und neues Präfix, Tabellen umbenannt (altes Präfix ersetzt, sonst vorangestellt) samt allen
     * Verweisen darauf, neue stabile Schlüssel.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function asCopy(array $definition, string $name, string $prefix): array
    {
        $old = rtrim((string) ($definition['app']['prefix'] ?? ''), '_');
        $old = $old === '' ? '' : $old.'_';
        $map = [];

        foreach ([...$definition['tables'], ...$definition['external_tables']] as $table) {
            $map[$table['name']] = $prefix.'_'.($old !== '' && str_starts_with($table['name'], $old) ? substr($table['name'], strlen($old)) : $table['name']);
        }

        $table = static fn (?string $n): ?string => $n === null ? null : ($map[$n] ?? $n);
        // „tabelle.feld“ und „quelle.feld>ziel“ (Tabellenverknüpfung)
        $ref = static function (?string $ref) use ($table): ?string {
            if ($ref === null || ! str_contains($ref, '.')) {
                return $ref;
            }

            if (str_contains($ref, '>')) {
                [$source, $target] = explode('>', $ref, 2);

                return (string) $table(Str::before($source, '.')).'.'.Str::after($source, '.').'>'.$table($target);
            }

            return $table(Str::before($ref, '.')).'.'.Str::after($ref, '.');
        };
        $fields = fn (array $fields): array => array_map(function (array $f) use ($table): array {
            if (isset($f['options']['table'])) {
                $f['options']['table'] = $table((string) $f['options']['table']);
            }

            // Formeln: Kind-Tabellen von Summen und Zählungen umbenennen (im Syntaxbaum, nicht im Text).
            if (filled($f['options']['formula'] ?? null)) {
                $f['options']['formula'] = Printer::print($this->renameAggregates($this->parser->parse((string) $f['options']['formula']), $table));
                unset($f['options']['ast']);
            }

            return $f;
        }, $fields);

        $definition['app'] = [...$definition['app'], 'name' => [(string) config('locales.default') => $name], 'prefix' => $prefix, 'interface_version' => 1];
        $definition['tables'] = array_map(static fn (array $t): array => [...$t, 'name' => $table($t['name']), 'fields' => $fields($t['fields']),
            'details' => array_map($ref, (array) ($t['details'] ?? []))], $definition['tables']);
        $definition['external_tables'] = array_map(static fn (array $t): array => [...$t, 'name' => $table($t['name']), 'fields' => $fields((array) $t['fields'])], $definition['external_tables']);
        $definition['report_links'] = array_map(static fn (array $l): array => [...$l, 'source' => $ref($l['source']), 'target_table' => $table($l['target_table']), 'target_field' => $ref($l['target_field'] ?? null)], $definition['report_links']);
        $definition['reports'] = array_map(static function (array $r) use ($table, $ref): array {
            $r['definition']['base'] = $table($r['definition']['base'] ?? null);
            $r['definition']['fields'] = array_map(static fn (mixed $f): mixed => is_array($f) ? [...$f,
                'path' => array_map(static fn (mixed $s): ?string => str_starts_with((string) $s, 'link:') ? 'link:'.$ref(substr((string) $s, 5)) : $ref((string) $s), (array) ($f['path'] ?? [])),
                'field' => $ref($f['field'] ?? null)] : $f, (array) ($r['definition']['fields'] ?? []));

            return $r;
        }, $definition['reports']);
        $definition['menus'] = array_map(static function (array $m) use ($table, $ref): array {
            if (($m['target']['type'] ?? null) === 'data') {
                $m['target']['table'] = $table($m['target']['table']);
                $m['target']['columns'] = array_map($ref, (array) ($m['target']['columns'] ?? []));
            }

            return $m;
        }, $definition['menus']);
        $definition['roles'] = array_map(static fn (array $r): array => [...$r, 'permissions' => array_map(static fn (string $p): string => str_starts_with($p, DataPermissions::PREFIX)
            ? DataPermissions::PREFIX.$table(Str::beforeLast(Str::after($p, DataPermissions::PREFIX), '.')).'.'.Str::afterLast($p, '.')
            : $p, (array) $r['permissions'])], $definition['roles']);
        $definition['tasks'] = array_map(static fn (array $t): array => isset($t['parameters']['table'])
            ? [...$t, 'parameters' => [...$t['parameters'], 'table' => $table($t['parameters']['table'])]] : $t, $definition['tasks']);
        // Dokumentvorlagen: Grundtabelle und Abschnitte über Kind-Tabellen ({{#tabelle}}, {{/tabelle__feld}}) umbenennen.
        $definition['documents'] = array_map(static fn (array $d): array => [
            ...$d,
            'table' => $table((string) $d['table']),
            ...array_map(static fn (?string $text): ?string => $text === null ? null : self::renameSections($text, $table), Arr::only($d, ['recipient', 'body', 'file_name'])),
        ], $definition['documents']);

        return $this->newKeys($definition);
    }

    /**
     * Abschnitte einer Dokumentvorlage, die nach einer Kind-Tabelle heissen ({{#tabelle}} … {{/tabelle}}, auch
     * tabelle__feld), auf die umbenannten Tabellen setzen. Felder tragen kein Präfix und bleiben.
     *
     * @param  callable(?string): ?string  $table
     */
    public static function renameSections(string $text, callable $table): string
    {
        return (string) preg_replace_callback('/(\{\{\s*[#^\/]\s*)([a-z][a-z0-9_]*)/', static function (array $m) use ($table): string {
            [$name, $field] = str_contains($m[2], '__') ? explode('__', $m[2], 2) : [$m[2], null];

            return $m[1].$table($name).($field !== null ? '__'.$field : '');
        }, $text);
    }

    /**
     * Summen und Zählungen über Kind-Tabellen: Tabellenname im ersten Argument umbenennen.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(?string): ?string  $table
     * @return array<string, mixed>
     */
    private function renameAggregates(array $node, callable $table): array
    {
        if (in_array($node['call'] ?? null, FormulaAnalyzer::AGGREGATES, true) && isset($node['args'][0])) {
            if (isset($node['args'][0]['field'])) {
                // count(tabelle)
                $node['args'][0]['field'] = $table((string) $node['args'][0]['field']);
            } else {
                // sum(tabelle.feld) und Ausdrücke wie sum(tabelle.menge * tabelle.preis) oder sum(if(tabelle.art = 'x', …)).
                $node['args'][0] = Printer::map((array) $node['args'][0], static function (array $n) use ($table): array {
                    if (isset($n['path'][0])) {
                        $n['path'][0] = $table((string) $n['path'][0]);
                    }

                    return $n;
                });
            }
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = $this->renameAggregates($child, $table);
            }
        }

        return $node;
    }

    /**
     * Neue stabile Schlüssel für alle Objekte (eine Kopie ist eine eigenständige App).
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function newKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($key === 'key' && is_string($item) && Str::isUuid($item)) {
                $value[$key] = (string) Str::uuid();
            } elseif (is_array($item) && $key !== 'options' && $key !== 'definition' && $key !== 'parameters') {
                $value[$key] = $this->newKeys($item);
            }
        }

        return $value;
    }
}
