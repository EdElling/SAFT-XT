<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\Diagram\DataModelGraph;
use App\DynamicData\Diagram\DataModelGraphBuilder;
use App\DynamicData\Diagram\MermaidErRenderer;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Schemas\MenuForm;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Services\DynamicSchemaService;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Livewire\Livewire;

/** Seite „Datenmodell“: Tabellen einer Menügruppe und ihre Verknüpfungen als Diagramm. */
class DataModelTest extends DynamicDataTestCase
{
    private Menu $sales;

    private Menu $documents;

    private Menu $master;

    private Menu $diagram;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = app(DynamicSchemaService::class);

        $schema->createTable(['name' => 'kunden', 'label' => 'Kunden "A" #1'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_unique' => true],
        ]);
        $schema->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
        ]);
        $schema->createTable(['name' => 'geheim', 'label' => 'Geheim'], [
            ['name' => 'code', 'label' => 'Code', 'type' => 'string'],
        ]);
        $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'belegnummer', 'label' => 'Belegnummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'is_required' => true, 'options' => ['table' => 'kunden', 'display_field' => 'name']],
            ['name' => 'geheim_id', 'label' => 'Geheimnis', 'type' => 'relation', 'options' => ['table' => 'geheim', 'display_field' => 'code']],
        ]);
        $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'is_required' => true, 'options' => ['table' => 'bestellungen', 'display_field' => 'belegnummer']],
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'alt', 'label' => 'Alt', 'type' => 'string'],
        ]);
        $orders = CustomTable::query()->where('name', 'bestellungen')->sole();
        $schema->updateTable($orders, ['detail_field_ids' => [$this->fieldId('positionen', 'bestellung_id')]]);
        $schema->updateField(CustomTable::query()->where('name', 'positionen')->sole()->fields()->where('name', 'alt')->sole(), ['is_active' => false]);

        // Verkauf: Kunden; Untergruppe Belege: Bestellungen, Positionen. Stammdaten: Artikel.
        $this->sales = Menu::create(['label' => 'Verkauf', 'sort_order' => 50]);
        $this->documents = Menu::create(['label' => 'Belege', 'parent_id' => $this->sales->id, 'sort_order' => 20]);
        $this->master = Menu::create(['label' => 'Stammdaten', 'sort_order' => 60]);
        $menu = app(DataMenu::class);
        $menu->create($this->table('kunden'), $this->sales->id);
        $menu->create($this->table('bestellungen'), $this->documents->id);
        $menu->create($this->table('positionen'), $this->documents->id);
        $menu->create($this->table('artikel'), $this->master->id);
        $this->diagram = Menu::create(['label' => 'Datenmodell', 'parent_id' => $this->sales->id, 'route' => DataModelPage::ROUTE, 'sort_order' => 90]);
    }

    private function table(string $name): CustomTable
    {
        return CustomTable::query()->where('name', $name)->sole();
    }

    private function graph(User $user, ?Menu $entry = null): DataModelGraph
    {
        return app(DataModelGraphBuilder::class)->build(($entry ?? $this->diagram)->fresh(), $user);
    }

    /** @return list<string> */
    private function edges(DataModelGraph $graph): array
    {
        return array_map(fn (array $e): string => $e['from'].'.'.$e['field']->name.'>'.$e['to'].($e['required'] ? ' 1' : ' 0..1').($e['detail'] ? ' detail' : ''), $graph->edges);
    }

    public function test_entry_references_itself_automatically(): void
    {
        $this->assertSame(['menu' => $this->diagram->id], $this->diagram->fresh()->route_parameters);
        $this->assertSame('/admin/data-model/'.$this->diagram->id, parse_url(route(DataModelPage::ROUTE, $this->diagram->fresh()->route_parameters), PHP_URL_PATH));
    }

    public function test_group_with_subgroups_outside_nodes_cardinality_and_details(): void
    {
        $graph = $this->graph($this->superAdmin());

        $this->assertEqualsCanonicalizing(['kunden', 'bestellungen', 'positionen'], array_keys($graph->tables));
        $this->assertEqualsCanonicalizing(['artikel', 'geheim'], array_keys($graph->outside));
        $this->assertEqualsCanonicalizing([
            'bestellungen.kunde_id>kunden 1',
            'bestellungen.geheim_id>geheim 0..1',
            'positionen.bestellung_id>bestellungen 1 detail',
            'positionen.artikel_id>artikel 0..1',
        ], $this->edges($graph));
        $this->assertNull($graph->tables['positionen']->fields->firstWhere('name', 'alt'), 'Inaktive Felder fehlen.');
    }

    public function test_only_what_the_user_may_see(): void
    {
        $user = $this->userWithPermissions([
            DataPermissions::name('kunden', 'view'),
            DataPermissions::name('bestellungen', 'view'),
            DataPermissions::name('artikel', 'view'),
        ]);

        $graph = $this->graph($user);

        $this->assertEqualsCanonicalizing(['kunden', 'bestellungen'], array_keys($graph->tables));
        $this->assertSame([], $graph->outside, 'Geheim ohne Recht, Artikel nur über unsichtbare Positionen verknüpft.');
        $this->assertSame(['bestellungen.kunde_id>kunden 1'], $this->edges($graph));
    }

    /** Ohne sichtbare Tabelle verschwinden der Datenmodell-Eintrag und eine dadurch leere Gruppe. */
    public function test_menu_hides_empty_data_model_and_its_otherwise_empty_group(): void
    {
        // Nur Artikel (andere Gruppe): in „Verkauf“ bliebe allein das Datenmodell übrig.
        $user = $this->userWithPermissions([DataPermissions::name('artikel', 'view')]);
        $this->assertFalse(app(DataModelGraphBuilder::class)->hasTables($this->diagram->fresh(), $user));
        $this->assertArrayNotHasKey('Verkauf', $this->navigation($user));
        $this->assertSame(['Artikel'], $this->navigation($user)['Stammdaten']);

        // Ein Recht in der Untergruppe genügt: Gruppe und Datenmodell erscheinen.
        $user = $this->userWithPermissions([DataPermissions::name('positionen', 'view')]);
        $this->assertTrue(app(DataModelGraphBuilder::class)->hasTables($this->diagram->fresh(), $user));
        $this->assertContains('Datenmodell', $this->navigation($user)['Verkauf']);

        // Andere sichtbare Einträge halten die Gruppe, nur das Datenmodell entfällt.
        Menu::create(['label' => 'Handbuch', 'parent_id' => $this->sales->id, 'url' => 'https://example.com', 'sort_order' => 95]);
        $user = $this->userWithPermissions([DataPermissions::name('artikel', 'view')]);
        $this->assertSame(['Handbuch'], $this->navigation($user)['Verkauf']);

        // Inaktive Tabellen zählen nicht, auch nicht für Super-Admins.
        foreach (['kunden', 'bestellungen', 'positionen'] as $name) {
            app(DynamicSchemaService::class)->updateTable($this->table($name), ['is_active' => false]);
        }
        $this->assertNotContains('Datenmodell', $this->navigation($this->superAdmin())['Verkauf']);
    }

    /** @return array<string, list<string>> Gruppen mit den Bezeichnungen ihrer Einträge (inkl. Untereinträge) */
    private function navigation(User $user): array
    {
        $labels = static fn (array $items): array => collect($items)->flatMap(
            fn (NavigationItem $item): array => [$item->getLabel(), ...array_map(fn (NavigationItem $c): string => $c->getLabel(), $item->getChildItems())],
        )->values()->all();

        return collect(app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user)->getNavigation())
            ->filter(fn ($group): bool => $group instanceof NavigationGroup)
            ->mapWithKeys(fn (NavigationGroup $group): array => [(string) $group->getLabel() => $labels($group->getItems())])
            ->all();
    }

    public function test_inactive_subgroup_and_inactive_tables_are_left_out(): void
    {
        $this->documents->update(['is_active' => false]);
        $this->assertSame(['kunden'], array_keys($this->graph($this->superAdmin())->tables));

        $this->documents->update(['is_active' => true]);
        app(DynamicSchemaService::class)->updateTable($this->table('kunden'), ['is_active' => false]);
        $graph = $this->graph($this->superAdmin());
        $this->assertEqualsCanonicalizing(['bestellungen', 'positionen'], array_keys($graph->tables));
        $this->assertArrayNotHasKey('kunden', $graph->outside);
    }

    public function test_entry_on_top_level_shows_all_data_tables(): void
    {
        $this->diagram->update(['parent_id' => null]);

        $this->assertEqualsCanonicalizing(['kunden', 'bestellungen', 'positionen', 'artikel'], array_keys($this->graph($this->superAdmin())->tables));
    }

    public function test_mermaid_text_in_both_variants_is_escaped(): void
    {
        $graph = $this->graph($this->superAdmin());
        $renderer = app(MermaidErRenderer::class);

        $compact = $renderer->render($graph, withFields: false);
        $this->assertStringStartsWith("erDiagram\n", $compact);
        $this->assertStringContainsString('T_kunden["Kunden #quot;A#quot; #35;1"]', $compact);
        $this->assertStringNotContainsString('{', $compact);
        $this->assertStringContainsString('T_bestellungen }o..|| T_kunden : "Kunde"', $compact);
        $this->assertStringContainsString('T_positionen }o--|| T_bestellungen : "Bestellung"', $compact);
        $this->assertStringContainsString('T_positionen }o..o| T_artikel : "Artikel"', $compact);
        $this->assertStringContainsString('class T_artikel,T_geheim outside', $compact);

        $expanded = $renderer->render($graph, withFields: true);
        $this->assertStringContainsString('integer id PK "ID"', $expanded);
        $this->assertStringContainsString('string name "Name *"', $expanded);
        $this->assertStringContainsString('string nummer UK "Nummer"', $expanded);
        $this->assertStringContainsString('relation kunde_id FK "Kunde *"', $expanded);
        $this->assertStringNotContainsString(' alt ', $expanded);
    }

    public function test_menu_form_offers_the_type_and_keeps_it(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateMenu::class)
            ->fillForm(['label.de' => 'Übersicht', 'parent_id' => $this->master->id, 'type' => MenuForm::TYPE_DIAGRAM])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = Menu::query()->where('label->de', 'Übersicht')->sole();
        $this->assertSame(DataModelPage::ROUTE, $entry->route);
        $this->assertSame(['menu' => $entry->id], $entry->route_parameters);
        $this->assertArrayNotHasKey(DataModelPage::ROUTE, MenuForm::routeOptions());

        Livewire::test(EditMenu::class, ['record' => $entry->getRouteKey()])
            ->assertFormSet(['type' => MenuForm::TYPE_DIAGRAM])
            ->fillForm(['parent_id' => $this->sales->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['menu' => $entry->id], $entry->fresh()->route_parameters);
        $this->get('/admin/data-model/'.$entry->id)->assertOk()->assertSee('Datenmodell: Verkauf');
    }

    public function test_page_shows_diagram_and_checks_access(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/admin/data-model/'.$this->diagram->id)
            ->assertOk()
            ->assertSee('Datenmodell: Verkauf')
            ->assertSee('T_kunden', false)
            ->assertSee(__('data_model.show_fields'))
            ->assertSee(__('data_model.legend.outside'));

        $this->get('/admin/data-model/999999')->assertNotFound();
        $this->get('/admin/data-model/'.$this->sales->id)->assertNotFound();

        $this->diagram->update(['permission_name' => 'menus.update']);
        $this->actingAs($this->userWithPermissions([DataPermissions::name('kunden', 'view')]))
            ->get('/admin/data-model/'.$this->diagram->id)->assertForbidden();

        $this->diagram->update(['permission_name' => null]);
        $this->actingAs($this->userWithPermissions())
            ->get('/admin/data-model/'.$this->diagram->id)->assertOk()->assertSee(__('data_model.empty'));
    }
}
