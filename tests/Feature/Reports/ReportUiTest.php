<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\DynamicData\DataPermissions;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Schemas\MenuForm;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ReportDesigner;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Report;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Reports\Editor\FieldCatalog;
use App\Services\DynamicSchemaService;
use Filament\Actions\Testing\TestAction;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Oberfläche der Auswertungen: Editor, Vorschau, Ergebnis, Liste, Menütyp. */
class ReportUiTest extends DynamicDataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'select', 'options' => ['options' => [['value' => 'ber', 'label' => 'Berlin'], ['value' => 'ham', 'label' => 'Hamburg']]]],
        ]);
        $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
        ]);

        $acme = DynamicRecord::forTableName('kunden')->create(['name' => 'ACME', 'ort' => 'ber']);
        $beta = DynamicRecord::forTableName('kunden')->create(['name' => 'Beta', 'ort' => 'ham']);

        foreach ([['B-1', $acme, '2026-01-10', '100.00'], ['B-2', $acme, '2026-02-20', '50.50'], ['B-3', $beta, '2026-02-05', '1200.00']] as [$nr, $kunde, $datum, $betrag]) {
            DynamicRecord::forTableName('bestellungen')->create(['nummer' => $nr, 'kunde_id' => $kunde->id, 'datum' => $datum, 'betrag' => $betrag]);
        }
    }

    public function test_designer_builds_a_crosstab_like_a_pivot_table_and_saves_it(): void
    {
        $designer = Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.datum'))
            ->call('setBucket', 'rows', 0, 'month')
            ->call('add', 'columns', $this->ref('kunden.ort', ['bestellungen.kunde_id']))
            ->call('add', 'values', $this->ref('bestellungen.betrag'))
            // Mit Spalten wechselt die Vorschau von selbst nicht – Kreuztabelle wählen.
            ->call('setVisual', 'crosstab')
            ->assertSee('data-report-crosstab', false)
            ->assertSeeInOrder(['Januar 2026', '100,00', 'Februar 2026', '50,50', '1.200,00'])
            ->assertSee('Summe Betrag je Monat (Datum), aufgeteilt nach Kunde › Ort');

        $this->assertSame('sum', $designer->get('state.values.0.agg'), 'Zahlen werden summiert.');

        $designer->fillForm(['name' => 'Umsatz je Monat und Ort', 'is_shared' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $report = Report::query()->sole();
        $this->assertSame([$this->admin->id, true], [$report->owner_id, $report->is_shared]);
        $this->assertSame(['type' => 'crosstab'], $report->definition['visual']);
        $this->assertCount(1, $report->definition['groups']);

        $this->get(ReportResource::getUrl('view', ['record' => $report]))
            ->assertOk()
            ->assertSee('Summe Betrag je Monat (Datum), aufgeteilt nach Kunde › Ort')
            ->assertSeeInOrder(['Berlin', 'Hamburg', 'Januar 2026']);
    }

    public function test_zones_can_be_reordered_moved_and_cleared(): void
    {
        $designer = Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.nummer'))
            ->call('add', 'rows', $this->ref('kunden.name', ['bestellungen.kunde_id']))
            ->assertSee('Liste: Nummer und Kunde › Name')
            ->call('move', 'rows', 1, 0)
            ->assertSee('Liste: Kunde › Name und Nummer')
            // Ein Feld von „Zeilen“ nach „Werte“ ziehen: jetzt gruppiert, Text wird gezählt.
            ->call('moveBetween', 'rows', 1, 'values', 0)
            ->assertSee('Anzahl Nummer je Kunde › Name')
            ->call('addCount')
            ->call('setAggregate', 0, 'count_distinct')
            ->assertSee('Anzahl verschiedener Nummer und Anzahl Datensätze je Kunde › Name')
            ->call('sortBy', 'value:1')
            ->call('sortBy', 'value:1')
            ->assertSee('sortiert nach Anzahl Datensätze absteigend');

        $this->assertSame('table', $designer->get('state.visual'));
        $designer->call('setVisual', 'pie')->assertSet('state.visual', 'pie')
            // Zweites Zeilenfeld: Kreis passt nicht mehr, die Vorschau fällt auf Balken zurück.
            ->call('add', 'rows', $this->ref('bestellungen.datum'))
            ->assertSet('state.visual', 'bar')
            ->call('remove', 'values', 0)
            ->call('remove', 'values', 0)
            ->assertSee('Liste: Kunde › Name und Datum')
            ->call('setBase', $this->table('kunden'))
            ->callMountedAction()
            ->assertSet('state.rows', []);
    }

    public function test_filter_dialog_sets_condition_and_value(): void
    {
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.nummer'))
            ->call('add', 'filters', $this->ref('kunden.ort', ['bestellungen.kunde_id']))
            ->assertActionMounted('filter')
            ->setActionData(['op' => 'eq', 'value' => 'ham'])
            ->callMountedAction()
            ->assertSee('nur Kunde › Ort gleich „Hamburg“')
            ->assertSee('B-3')
            ->assertDontSee('B-1');
    }

    public function test_formula_dialog_adds_calculated_values_and_list_columns(): void
    {
        $designer = Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.kunde_id'))
            ->mountAction('formula', ['zone' => 'values'])
            ->assertActionMounted('formula')
            ->setActionData(['formula' => '[Kunde › Name] * 2'])
            ->callMountedAction()
            ->assertHasActionErrors(['formula'])
            ->setActionData(['formula' => '[Betrag] × 2', 'agg' => 'sum', 'label' => 'Doppelt', 'scale' => 1])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet('state.values.0.expr', ['op' => '*', 'left' => ['field' => $this->ref('bestellungen.betrag')], 'right' => ['num' => '2']])
            ->assertSee('Doppelt')
            ->assertSee('301,0')
            ->assertSee('2.400,0')
            // Wieder öffnen zeigt die Formel mit Namen; die Berechnung lässt sich direkt umstellen.
            ->mountAction('formula', ['zone' => 'values', 'index' => 0])
            ->assertActionDataSet(['formula' => '[Betrag] × 2', 'agg' => 'sum', 'label' => 'Doppelt', 'scale' => 1])
            ->callMountedAction()
            ->call('setAggregate', 0, 'count')
            ->assertSet('state.values.0.agg', 'sum')
            ->call('setAggregate', 0, 'max')
            ->assertSet('state.values.0.agg', 'max')
            ->fillForm(['name' => 'Formel'])
            ->call('save');

        $definition = Report::query()->where('name', 'Formel')->sole()->definition;
        $this->assertSame(['max', 'Doppelt', 1], [$definition['measures'][0]['agg'], $definition['measures'][0]['label'], $definition['measures'][0]['scale']]);

        // Liste: berechnete Spalte.
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.nummer'))
            ->mountAction('formula', ['zone' => 'rows'])
            ->setActionData(['formula' => 'round([Betrag] ÷ 3; 1)', 'label' => null, 'scale' => 1])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('33,3')
            ->assertSee('round([Betrag] ÷ 3; 1)');
    }

    public function test_calc_dialog_computes_with_values_and_grouped_number_columns(): void
    {
        $designer = Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.kunde_id'))
            ->call('add', 'values', $this->ref('bestellungen.betrag'))
            ->call('addCount')
            ->mountAction('calc')
            ->assertActionMounted('calc')
            ->setActionData(['formula' => '[Kunde] * 2', 'label' => null, 'scale' => 2])
            ->callMountedAction()
            ->assertHasActionErrors(['formula'])
            ->setActionData(['formula' => '[Summe Betrag] ÷ [Anzahl Datensätze]', 'label' => 'Je Bestellung', 'scale' => 2])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet('state.values.2.agg', 'calc')
            ->assertSee('Je Bestellung')
            // ACME: 150,50 ÷ 2; Beta: 1200 ÷ 1.
            ->assertSee('75,25')
            ->assertSee('1.200,00')
            ->mountAction('calc', ['index' => 2])
            ->assertActionDataSet(['formula' => '[Summe Betrag] ÷ [Anzahl Datensätze]', 'label' => 'Je Bestellung', 'scale' => 2])
            ->callMountedAction()
            ->call('setAggregate', 2, 'sum')
            ->assertSet('state.values.2.agg', 'calc');

        // Verschieben eines anderen Werts ändert nicht, worauf die Formel zeigt.
        $designer->call('move', 'values', 1, 0)
            ->assertSee('75,25')
            ->fillForm(['name' => 'Kennzahl'])
            ->call('save');

        $measures = Report::query()->where('name', 'Kennzahl')->sole()->definition['measures'];
        $this->assertSame(['count', 'sum', 'calc'], array_column($measures, 'agg'));
        $this->assertSame(['op' => '/', 'left' => ['field' => $measures[1]['key']], 'right' => ['field' => $measures[0]['key']]], $measures[2]['calc']);
    }

    public function test_formula_dialog_checks_while_typing(): void
    {
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.kunde_id'))
            ->mountAction('formula', ['zone' => 'values'])
            ->setActionData(['formula' => '[Kunde › Name] * 2'])
            ->assertHasActionErrors(['formula'])
            ->setActionData(['formula' => '[Betrag] * 2'])
            ->assertHasNoActionErrors(['formula']);
    }

    public function test_invalid_or_unnamed_report_is_not_saved(): void
    {
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->fillForm(['name' => 'Leer'])
            ->call('save')
            ->assertNotified('Die Auswertung lässt sich so nicht speichern');

        Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('bestellungen'))
            ->call('add', 'rows', $this->ref('bestellungen.nummer'))
            ->call('save')
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertSame(0, Report::query()->count());
    }

    public function test_edit_opens_the_saved_report_in_the_designer(): void
    {
        $report = $this->listReport();

        Livewire::test(ReportDesigner::class, ['record' => $report->getRouteKey()])
            ->assertSet('state.base', $this->table('bestellungen'))
            ->assertSet('state.sort', 'row:1')
            ->assertSet('state.sortDir', 'desc')
            ->assertSee('Liste: Nummer und Betrag; sortiert nach Betrag absteigend')
            ->fillForm(['name' => 'Bestellungen neu'])
            ->set('state.limit', 2)
            ->call('save');

        $this->assertSame(['Bestellungen neu', 2], [$report->fresh()->name, $report->fresh()->definition['limit']]);

        $other = $this->userWithPermissions(['reports.view', 'reports.create', DataPermissions::name('bestellungen', 'view')]);
        $this->actingAs($other);
        $this->get(ReportResource::getUrl('edit', ['record' => $report]))->assertNotFound();
    }

    public function test_charts_and_key_figure_are_rendered(): void
    {
        $chart = $this->store('Balken', [
            'base' => $this->table('bestellungen'),
            'fields' => [['key' => 'k', 'path' => [$this->fieldIdOf('bestellungen.kunde_id')], 'field' => $this->fieldIdOf('kunden.name')], ['key' => 'b', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.betrag')]],
            'groups' => ['k'],
            'measures' => [['key' => 's', 'field' => 'b', 'agg' => 'sum']],
            'visual' => ['type' => 'bar'],
        ]);
        $this->get(ReportResource::getUrl('view', ['record' => $chart]))
            ->assertOk()
            ->assertSee('data-chart-type="bar"', false)
            ->assertSeeInOrder(['ACME', 'Beta', '150.5', '1200'], false);

        $kpi = $this->store('Umsatz gesamt', [
            'base' => $this->table('bestellungen'),
            'fields' => [['key' => 'b', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.betrag')]],
            'measures' => [['key' => 's', 'field' => 'b', 'agg' => 'sum']],
            'visual' => ['type' => 'kpi'],
        ]);
        $this->get(ReportResource::getUrl('view', ['record' => $kpi]))->assertOk()->assertSee('data-report-kpi', false)->assertSee('1.350,50');
    }

    public function test_list_shows_only_reports_the_person_may_run(): void
    {
        $sales = $this->userWithPermissions(['reports.view', 'reports.create', DataPermissions::name('bestellungen', 'view')]);
        $ownersReport = $this->listReport(shared: true);
        $withCustomers = $this->store('Mit Kunden', [
            'base' => $this->table('bestellungen'),
            'fields' => [['key' => 'k', 'path' => [$this->fieldIdOf('bestellungen.kunde_id')], 'field' => $this->fieldIdOf('kunden.name')]],
        ], shared: true);
        $private = $this->listReport();
        $this->actingAs($sales);

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$ownersReport])
            ->assertCanNotSeeTableRecords([$withCustomers, $private])
            ->callAction(TestAction::make('duplicate')->table($ownersReport));

        $copy = Report::query()->where('owner_id', $sales->id)->sole();
        $this->assertSame('Bestellungen (Kopie)', $copy->name);
        $this->assertFalse($copy->is_shared);

        // Geteilt, aber Kunden nicht lesbar: verständliche Meldung statt Fehlerseite.
        $this->get(ReportResource::getUrl('view', ['record' => $withCustomers]))
            ->assertOk()
            ->assertSee('Für diese Auswertung fehlt Ihnen das Leserecht auf: Kunden.');
        // Fremde private Auswertung: nicht einmal ihre Existenz wird verraten.
        $this->get(ReportResource::getUrl('view', ['record' => $private]))->assertNotFound();
        $this->get(ReportResource::getUrl('edit', ['record' => $ownersReport]))->assertForbidden();
    }

    public function test_menu_entry_of_type_report_follows_the_data_permissions(): void
    {
        $report = $this->store('Mit Kunden', [
            'base' => $this->table('bestellungen'),
            'fields' => [['key' => 'k', 'path' => [$this->fieldIdOf('bestellungen.kunde_id')], 'field' => $this->fieldIdOf('kunden.name')]],
        ], shared: true);
        $group = Menu::create(['label' => 'Verkauf', 'sort_order' => 50]);

        Livewire::test(CreateMenu::class)
            ->fillForm(['label.de' => 'Kundenliste', 'parent_id' => $group->id, 'type' => MenuForm::TYPE_REPORT, 'report' => (string) $report->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = Menu::query()->where('route', ReportResource::VIEW_ROUTE)->sole();
        $this->assertSame(['record' => (string) $report->id], $entry->route_parameters);
        $this->assertSame('reports.view', $entry->permission_name);

        $base = ['reports.view', DataPermissions::name('bestellungen', 'view')];
        $this->assertNotContains('Kundenliste', $this->labels($this->userWithPermissions($base)));
        $this->assertContains('Kundenliste', $this->labels($this->userWithPermissions([...$base, DataPermissions::name('kunden', 'view')])));
    }

    // --- Hilfen -----------------------------------------------------------------------

    public function test_base_table_choice_is_limited_to_linked_tables_and_asks_before_clearing(): void
    {
        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);
        $schema->createTable(['name' => 'notizen', 'label' => 'Notizen'], [['name' => 'text', 'label' => 'Text', 'type' => 'string']]);

        // In beide Richtungen und über mehrere Stufen: Kunden ← Bestellungen ← Positionen.
        $reachable = app(FieldCatalog::class)->reachableFrom($this->table('kunden'), $this->admin);
        sort($reachable);
        $this->assertSame([$this->table('kunden'), $this->table('bestellungen'), $this->table('positionen')], $reachable);

        $designer = Livewire::test(ReportDesigner::class)
            ->assertDontSee('Auswahl aufheben')
            ->call('setBase', $this->table('kunden'))
            ->assertSee('Auswahl aufheben')
            ->assertSee('Nicht verknüpft mit „Kunden“')
            ->assertSeeHtml('data-base-option="'.$this->table('notizen').'" data-kind="internal"')
            // Nicht erreichbar: ignoriert, auch bei direktem Aufruf.
            ->call('setBase', $this->table('notizen'))
            ->assertSet('state.base', $this->table('kunden'))
            // Ohne Felder ohne Rückfrage wechseln.
            ->call('setBase', $this->table('positionen'))
            ->assertActionNotMounted('confirmBaseChange')
            ->assertSet('state.base', $this->table('positionen'))
            ->call('add', 'rows', $this->ref('positionen.menge'));

        $this->assertStringContainsString('disabled', $this->optionTag($designer->html(), $this->table('notizen')));
        $this->assertStringNotContainsString('disabled', $this->optionTag($designer->html(), $this->table('bestellungen')));

        // Mit Feldern: erst fragen.
        $designer->call('setBase', $this->table('bestellungen'))
            ->assertActionMounted('confirmBaseChange')
            ->assertSet('state.base', $this->table('positionen'))
            ->callMountedAction()
            ->assertSet('state.base', $this->table('bestellungen'))
            ->assertSet('state.rows', [])
            ->call('add', 'rows', $this->ref('bestellungen.nummer'))
            ->call('setBase', null)
            ->assertActionMounted('confirmBaseChange')
            ->callMountedAction()
            ->assertSet('state.base', null)
            // Danach ist wieder alles wählbar.
            ->call('setBase', $this->table('notizen'))
            ->assertSet('state.base', $this->table('notizen'));
    }

    private function optionTag(string $html, int $id): string
    {
        preg_match('/<button[^>]*data-base-option="'.$id.'"[^>]*>/s', $html, $match);

        return $match[0] ?? '';
    }

    public function test_list_shows_and_filters_the_display_type(): void
    {
        $list = $this->listReport();
        $chart = $this->store('Umsatz je Kunde', [
            'base' => $this->table('bestellungen'),
            'fields' => [['key' => 'k', 'path' => [$this->fieldIdOf('bestellungen.kunde_id')], 'field' => $this->fieldIdOf('kunden.name')], ['key' => 'b', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.betrag')]],
            'groups' => ['k'],
            'measures' => [['key' => 's', 'agg' => 'sum', 'field' => 'b']],
            'visual' => ['type' => 'bar'],
        ]);

        Livewire::test(ListReports::class)
            ->assertTableColumnStateSet('visual', 'table', $list)
            ->assertTableColumnStateSet('visual', 'bar', $chart)
            ->assertSee('Balkendiagramm')
            ->filterTable('visual', 'bar')
            ->assertCanSeeTableRecords([$chart])
            ->assertCanNotSeeTableRecords([$list])
            ->filterTable('visual', 'table')
            ->assertCanSeeTableRecords([$list])
            ->assertCanNotSeeTableRecords([$chart]);
    }

    public function test_list_filters_by_base_table_owner_shared_and_date_and_searches_descriptions(): void
    {
        $orders = $this->listReport(shared: true);
        $customers = Report::create([
            'name' => 'Kundenliste', 'description' => 'Alle Kunden mit Ort', 'owner_id' => $this->userWithPermissions(['reports.view', 'reports.create', 'data.kunden.view'])->id, 'is_shared' => true,
            'definition' => ['base' => $this->table('kunden'), 'fields' => [['key' => 'n', 'path' => [], 'field' => $this->fieldIdOf('kunden.name')]]],
        ]);
        $customers->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$orders, $customers])
            ->filterTable('base', $this->table('kunden'))
            ->assertCanSeeTableRecords([$customers])
            ->assertCanNotSeeTableRecords([$orders])
            ->resetTableFilters()
            ->filterTable('owner_id', $this->admin->id)
            ->assertCanSeeTableRecords([$orders])
            ->assertCanNotSeeTableRecords([$customers])
            ->resetTableFilters()
            ->filterTable('updated_at', ['from' => now()->subDays(2)->toDateString()])
            ->assertCanSeeTableRecords([$orders])
            ->assertCanNotSeeTableRecords([$customers])
            ->resetTableFilters()
            ->searchTable('mit Ort')
            ->assertCanSeeTableRecords([$customers])
            ->assertCanNotSeeTableRecords([$orders]);

        // Suche und Filter gelten für die Sitzung: zurücksetzen.
        $orders->update(['is_shared' => false]);
        Livewire::test(ListReports::class)
            ->searchTable('')
            ->filterTable('is_shared', false)
            ->assertCanSeeTableRecords([$orders])
            ->assertCanNotSeeTableRecords([$customers]);
    }

    private function listReport(bool $shared = false): Report
    {
        return $this->store('Bestellungen', [
            'base' => $this->table('bestellungen'),
            'fields' => [['key' => 'n', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.nummer')], ['key' => 'b', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.betrag')]],
            'sort' => [['key' => 'b', 'dir' => 'desc']],
        ], $shared);
    }

    /** @param  array<string, mixed>  $definition */
    private function store(string $name, array $definition, bool $shared = false): Report
    {
        return Report::create(['name' => $name, 'owner_id' => $this->admin->id, 'definition' => $definition, 'is_shared' => $shared]);
    }

    /** @return list<string> */
    private function labels(User $user): array
    {
        $labels = [];

        foreach (app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user)->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                /** @var NavigationItem $item */
                $labels[] = (string) $item->getLabel();
            }
        }

        return $labels;
    }

    private function table(string $name): int
    {
        return (int) CustomTable::query()->where('name', $name)->value('id');
    }

    private function fieldIdOf(string $qualified): int
    {
        [$table, $field] = explode('.', $qualified);

        return (int) CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $field)->value('id');
    }

    /** @param  list<string>  $path */
    private function ref(string $field, array $path = []): string
    {
        return FieldCatalog::ref(array_map($this->fieldIdOf(...), $path), $this->fieldIdOf($field));
    }
}
