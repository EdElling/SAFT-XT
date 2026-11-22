<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\DynamicData\DataMenu;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Schema\FieldDefinitionNormalizer;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\App;
use App\Models\AppManual;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\RecordFile;
use App\Models\Report;
use App\Reports\DefinitionResolver;
use App\Reports\ReportDefinition;
use App\Services\DynamicSchemaService;
use Illuminate\Support\Collection;

/**
 * Gemeinsame Bausteine der Demo-Seeder (Shop, Lager, Vertrieb, Forschung): Menügruppe, Tabellen samt
 * Menüeinträgen, Master-Detail, Tabellenregeln, berechnete Felder und Beispiel-Auswertungen.
 * Alles wiederholbar: Vorhandenes wird nicht dupliziert, spätere Anpassungen bleiben erhalten.
 */
trait SeedsDemoTables
{
    /** Demo-Daten sind ein fester Datensatz: Geschäftsregeln installierter Plugins (Ereignisse) wirken hier nicht. */
    public function __invoke(array $parameters = []): mixed
    {
        return DynamicRecord::withoutPluginEvents(fn (): mixed => parent::__invoke($parameters));
    }

    /**
     * App der Demo: uuid (fest), name je Sprache, prefix, icon, sort_order.
     *
     * @return array{uuid: string, name: array<string, string>, prefix: string|null, icon: string, sort_order: int, manual?: string}
     */
    abstract public function demoAppDefinition(): array;

    /** @return list<string> Namen der Demo-Tabellen in Anlegereihenfolge (Verknüpfungsziele zuerst) */
    public function demoTableNames(): array
    {
        return array_values(array_column($this->definitions(), 'name'));
    }

    /** Ist die Demo vollständig geladen (alle ihre Tabellen vorhanden)? */
    public function demoInstalled(): bool
    {
        return CustomTable::query()->whereIn('name', $this->demoTableNames())->count() === count($this->demoTableNames());
    }

    /**
     * Setzt nur die Daten der Demo zurück (geplante Aufgabe „Demo-Daten zurücksetzen“): leert ihre Tabellen – in
     * umgekehrter Anlegereihenfolge, Verweise einer Tabelle auf sich selbst vorher geleert – und füllt sie neu.
     * Aufbau der Tabellen, Menüs, Auswertungen, Regeln, Rollen und Rechte bleiben unverändert; andere Tabellen werden
     * nicht berührt. Alles in einer Transaktion: Scheitert etwas (z. B. eine fremde Tabelle verweist auf Demo-Daten),
     * bleibt der alte Stand.
     *
     * @return int Zahl der danach vorhandenen Datensätze
     */
    public function resetDemoData(): int
    {
        $names = $this->demoTableNames();
        $tables = CustomTable::query()->whereIn('name', $names)->get()->keyBy('name');

        if ($tables->count() !== count($names)) {
            throw new BusinessRuleException(__('tasks.demo_reset.not_installed', ['tables' => implode(', ', array_diff($names, $tables->keys()->all()))]));
        }

        $namespace = app(DynamicDataNamespace::class);
        $db = $namespace->connection();

        // Dateien vor dem Zurücksetzen: Ihre Datensätze werden direkt in der Datenbank ersetzt (B-021); Dateien, die die
        // Demo beim Neuladen selbst anlegt (z. B. Bilder im Gitarrenshop), bleiben.
        $oldFiles = RecordFile::query()->whereIn('custom_table_id', $tables->pluck('id'))->get();

        return DynamicRecord::withoutPluginEvents(function () use ($names, $tables, $namespace, $db, $oldFiles): int {
            $db->transaction(function () use ($tables): void {
                $this->deleteDemoRecords($tables);
                $this->reseedDemoData();
            });

            app(RecordFiles::class)->delete($oldFiles);

            $done = [];
            $count = 0;

            foreach ($names as $name) {
                app(Recalculator::class)->recomputeTable($tables[$name], $done);
                $count += $db->table($namespace->qualify($name))->count();
            }

            return $count;
        });
    }

    /**
     * Leert nur die Tabellen der Demo, ohne sie neu zu füllen – für Demos, deren Daten auf eine andere Demo verweisen
     * (Vertrieb auf Lager-Artikel): vor dem Zurücksetzen der anderen leeren, danach selbst zurücksetzen.
     */
    public function clearDemoData(): void
    {
        $tables = CustomTable::query()->whereIn('name', $this->demoTableNames())->get()->keyBy('name');
        app(DynamicDataNamespace::class)->connection()->transaction(fn () => $this->deleteDemoRecords($tables));
    }

    /**
     * In umgekehrter Anlegereihenfolge leeren, Verweise einer Tabelle auf sich selbst vorher auf leer setzen.
     *
     * @param  Collection<string, CustomTable>  $tables
     */
    private function deleteDemoRecords(Collection $tables): void
    {
        $namespace = app(DynamicDataNamespace::class);
        $db = $namespace->connection();

        foreach (array_reverse($this->demoTableNames()) as $name) {
            if (! $tables->has($name)) {
                continue;
            }

            $query = $db->table($namespace->qualify($name));
            $selfReferences = $tables[$name]->fields()->where('type', 'relation')->get()
                ->filter(static fn (CustomField $field): bool => ($field->optionsArray()['table'] ?? null) === $name);

            foreach ($selfReferences as $field) {
                (clone $query)->update([$field->name => null]);
            }

            (clone $query)->delete();
        }
    }

    /** Füllt die (leeren) Tabellen der Demo mit ihren Beispieldaten. */
    abstract protected function reseedDemoData(): void;

    /**
     * Untermenüs einer Demo-Gruppe (dritte Ebene) anlegen und Einträge dorthin umhängen – nur solche, die noch direkt in
     * der Gruppe oder schon im Untermenü liegen; anderswohin verschobene bleiben. Wiederholbar.
     *
     * @param  list<array{label: array<string, string>, icon: string, sort_order: int, entries: list<array{0: 'table'|'report'|'route', 1: string}>}>  $submenus
     */
    protected function demoSubmenus(Menu $group, array $submenus): void
    {
        foreach ($submenus as $submenu) {
            $parent = Menu::query()->where('parent_id', $group->id)->whereNull('route')->whereNull('url')->where('label->de', $submenu['label']['de'])->first()
                ?? Menu::create(['parent_id' => $group->id, 'label' => $submenu['label'], 'icon' => $submenu['icon'], 'sort_order' => $submenu['sort_order'], 'is_active' => true]);

            foreach ($submenu['entries'] as $index => [$kind, $key]) {
                $query = Menu::query()->whereIn('parent_id', [$group->id, $parent->id]);
                $entry = match ($kind) {
                    'table' => $query->where('route', DataMenu::ROUTE)->where('route_parameters->table', $key)->first(),
                    'report' => ($report = Report::query()->where('name', $key)->first()) === null ? null
                        : $query->where('route', ReportResource::VIEW_ROUTE)->where('route_parameters->record', (string) $report->id)->first(),
                    'route' => $query->where('route', $key)->first(),
                };

                $entry?->update(['parent_id' => $parent->id, 'sort_order' => ($index + 1) * 10]);
            }
        }
    }

    /**
     * App der Demo (B-107): mit festem Schlüssel, damit das Einspielen wiederholbar ist. Sind Tabellen der Demo schon
     * da (z. B. in „Bestehende Daten“ nach der Umstellung), gilt deren App – verschoben wird nur von Hand.
     */
    public function demoApp(): App
    {
        $existing = CustomTable::query()->whereIn('name', $this->demoTableNames())->whereNotNull('app_id')->value('app_id');

        if ($existing !== null && ($app = App::query()->find($existing)) !== null) {
            return $app;
        }

        $definition = $this->demoAppDefinition();
        unset($definition['manual']);

        return App::query()->firstOrCreate(['uuid' => $definition['uuid']], $definition);
    }

    /**
     * Handbuch der Demo-App aus database/seeders/manuals/<name>/<sprache>.md (B-107). Nur fehlende Sprachen werden
     * angelegt; ein über die Seite „Apps“ geändertes Handbuch bleibt.
     */
    public function seedDemoManual(): void
    {
        $name = $this->demoAppDefinition()['manual'] ?? null;

        if (! is_string($name) || ! is_dir($directory = database_path('seeders/manuals/'.$name))) {
            return;
        }

        $app = $this->demoApp();

        foreach (glob($directory.'/*.md') ?: [] as $file) {
            AppManual::query()->firstOrCreate(
                ['app_id' => $app->id, 'locale' => basename($file, '.md')],
                ['content' => (string) file_get_contents($file)],
            );
        }
    }

    /**
     * Dokumentvorlage der Demo (B-124) aus database/seeders/documents/<datei>.html – nur, wenn es sie (fester Schlüssel)
     * noch nicht gibt; eine geänderte Vorlage bleibt.
     */
    protected function demoDocumentTemplate(string $uuid, string $table, string $name, string $file, string $recipient, string $fileName, ?string $fileField = null): void
    {
        $customTable = CustomTable::query()->where('name', $table)->first();

        if ($customTable === null || $customTable->app_id === null || DocumentTemplate::query()->where('uuid', $uuid)->exists()) {
            return;
        }

        DocumentTemplate::query()->create([
            'uuid' => $uuid,
            'app_id' => $customTable->app_id,
            'custom_table_id' => $customTable->id,
            'name' => $name,
            'recipient' => $recipient,
            'body' => (string) file_get_contents(database_path('seeders/documents/'.$file.'.html')),
            'file_name' => $fileName,
            'file_field' => $fileField,
        ]);
    }

    /** @param  array<string, string>  $labels  Bezeichnung je Sprache */
    protected function demoGroup(array $labels, int $sortOrder): Menu
    {
        return Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->where('label->de', $labels['de'])->first()
            ?? Menu::create(['app_id' => $this->demoApp()->id, 'label' => $labels, 'sort_order' => $sortOrder, 'is_active' => true]);
    }

    /**
     * Legt fehlende Tabellen an (in der angegebenen Reihenfolge, Verknüpfungsziele zuerst) und hängt sie
     * in die Gruppe. Gibt die Namen der neu angelegten Tabellen zurück.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return list<string>
     */
    protected function demoTables(Menu $group, array $definitions): array
    {
        $this->demoAssertNamesFree($definitions);
        $schema = app(DynamicSchemaService::class);
        $menu = app(DataMenu::class);
        $created = [];

        foreach ($definitions as $definition) {
            $table = CustomTable::query()->where('name', $definition['name'])->first();

            if ($table === null) {
                $table = $schema->createTable(['app_id' => $this->demoApp()->id, ...$definition], $definition['fields']);
                $created[] = $table->name;
            }

            if (! $menu->exists($table)) {
                $menu->create($table, $group->id);
            }
        }

        $menu->ensureDataModel($group);

        return $created;
    }

    /**
     * Vor dem ersten Schritt: Belegt eine angebundene Tabelle einen Namen der Demo, bricht sie ab, ohne etwas
     * anzulegen. Vorhandene SAFT-Tabellen gleichen Namens gelten als die der Demo (erneutes Laden).
     *
     * @param  list<array<string, mixed>>  $definitions
     */
    protected function demoAssertNamesFree(array $definitions): void
    {
        $taken = CustomTable::query()
            ->whereIn('name', array_column($definitions, 'name'))
            ->where('source', CustomTable::SOURCE_EXTERNAL)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($taken !== []) {
            throw new BusinessRuleException(
                'Demo nicht geladen: Die Namen '.implode(', ', $taken).' sind bereits von angebundenen Tabellen (Table-Connector) belegt. '
                .'Anbindung dort entfernen oder die Demo weglassen.',
                'demo.name_taken',
            );
        }
    }

    /**
     * Master-Detail: je übergeordneter Tabelle die Verknüpfungsfelder ihrer Details (tabelle.feld),
     * nur wenn noch keine Details konfiguriert sind.
     *
     * @param  array<string, list<string>>  $details
     */
    protected function demoDetails(array $details): void
    {
        foreach ($details as $parent => $relations) {
            $table = CustomTable::query()->where('name', $parent)->firstOrFail();

            if (! empty($table->detail_field_ids)) {
                continue;
            }

            app(DynamicSchemaService::class)->updateTable($table, ['detail_field_ids' => array_map(
                fn (string $relation): int => $this->demoFieldId($relation),
                $relations,
            )]);
        }
    }

    /**
     * Tabellenregeln, sofern die Tabelle noch keine hat.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    protected function demoRules(string $tableName, array $rules): void
    {
        $table = CustomTable::query()->with('fields')->where('name', $tableName)->firstOrFail();

        if ($table->tableRules() === []) {
            app(DynamicSchemaService::class)->updateTable($table, ['validation_rules' => $rules]);
        }
    }

    /**
     * Berechnete Felder nach den Daten anlegen – SAFT rechnet sie dabei für alle Datensätze aus.
     *
     * @param  list<array{0: string, 1: string, 2: array<string, string>, 3: string, 4: array<string, mixed>}>  $fields  Tabelle, Name, Bezeichnung, Formel, Ergebnis
     */
    protected function demoComputed(array $fields): void
    {
        foreach ($fields as [$tableName, $name, $label, $formula, $result]) {
            $table = CustomTable::query()->where('name', $tableName)->firstOrFail();

            if (! $table->fields()->where('name', $name)->exists()) {
                app(DynamicSchemaService::class)->addField($table, ['name' => $name, 'label' => $label, 'type' => 'computed', 'options' => [...$result, 'formula' => $formula]]);
            }
        }
    }

    /**
     * Geteilte Beispiel-Auswertung samt Menüeintrag in der Gruppe. Passt die Definition nicht zum
     * Datenmodell, scheitert der Seeder laut.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $menuLabel
     */
    protected function demoReport(Menu $group, string $name, string $description, array $definition, array $menuLabel, string $icon): Report
    {
        app(DefinitionResolver::class)->resolve(ReportDefinition::fromArray($definition));

        $report = Report::query()->firstOrCreate(['name' => $name], [
            'description' => $description,
            'definition' => ReportDefinition::fromArray($definition)->toArray(),
            'is_shared' => true,
        ]);

        if (! Menu::query()->where('route', ReportResource::VIEW_ROUTE)->where('route_parameters->record', (string) $report->id)->exists()) {
            Menu::create([
                'parent_id' => $group->id,
                'label' => $menuLabel,
                'route' => ReportResource::VIEW_ROUTE,
                'route_parameters' => ['record' => (string) $report->id],
                'icon' => $icon,
                'permission_name' => 'reports.view',
                'sort_order' => ((int) Menu::query()->where('parent_id', $group->id)->max('sort_order')) + 10,
                'is_active' => true,
            ]);
        }

        return $report;
    }

    /**
     * Verknüpfungen „beim Kopieren des übergeordneten Datensatzes mitkopieren“ (B-123), auch in schon geladenen Demos;
     * andere Optionen der Verknüpfung bleiben.
     *
     * @param  list<string>  $relations  „tabelle.feld“
     */
    protected function demoCopyWithParent(array $relations): void
    {
        foreach ($relations as $relation) {
            $field = CustomField::query()->find($this->demoFieldId($relation));

            if ($field instanceof CustomField && ! ($field->optionsArray()[FieldDefinitionNormalizer::COPY_WITH_PARENT] ?? false)) {
                app(DynamicSchemaService::class)->updateField($field, ['options' => [...$field->optionsArray(), FieldDefinitionNormalizer::COPY_WITH_PARENT => true]]);
            }
        }
    }

    /** ID eines Feldes als „tabelle.feld“. */
    protected function demoFieldId(string $qualified): int
    {
        [$table, $field] = explode('.', $qualified, 2);

        return (int) CustomField::query()
            ->whereHas('custom_table', fn ($q) => $q->where('name', $table))
            ->where('name', $field)
            ->value('id');
    }

    protected function demoTableId(string $name): int
    {
        return (int) CustomTable::query()->where('name', $name)->value('id');
    }

    /** @return array<string, string> Text je Sprache */
    protected static function demoText(string $de, string $en, string $fr, string $it): array
    {
        return ['de' => $de, 'en' => $en, 'fr' => $fr, 'it' => $it];
    }
}
