<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\DynamicData\DataMenu;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Formula\Recalculator;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Report;
use App\Reports\DefinitionResolver;
use App\Reports\ReportDefinition;
use App\Services\DynamicSchemaService;

/**
 * Gemeinsame Bausteine der Demo-Seeder (Shop, Lager, Forschung): Menügruppe, Tabellen samt
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

        return DynamicRecord::withoutPluginEvents(function () use ($names, $tables, $namespace, $db): int {
            $db->transaction(function () use ($names, $tables, $namespace, $db): void {
                foreach (array_reverse($names) as $name) {
                    $query = $db->table($namespace->qualify($name));
                    $selfReferences = $tables[$name]->fields()->where('type', 'relation')->get()
                        ->filter(static fn (CustomField $field): bool => ($field->optionsArray()['table'] ?? null) === $name);

                    foreach ($selfReferences as $field) {
                        (clone $query)->update([$field->name => null]);
                    }

                    (clone $query)->delete();
                }

                $this->reseedDemoData();
            });

            $done = [];
            $count = 0;

            foreach ($names as $name) {
                app(Recalculator::class)->recomputeTable($tables[$name], $done);
                $count += $db->table($namespace->qualify($name))->count();
            }

            return $count;
        });
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

    /** @param  array<string, string>  $labels  Bezeichnung je Sprache */
    protected function demoGroup(array $labels, int $sortOrder): Menu
    {
        return Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->where('label->de', $labels['de'])->first()
            ?? Menu::create(['label' => $labels, 'sort_order' => $sortOrder, 'is_active' => true]);
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
                $table = $schema->createTable($definition, $definition['fields']);
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
