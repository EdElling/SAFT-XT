<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\DynamicData\Diagram\DataModelGraphBuilder;
use App\DynamicData\SchemaVerifier;
use App\Filament\Pages\DataModelPage;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTableState;
use App\Navigation\MenuNavigationBuilder;
use Database\Seeders\DemoDataSeeder;
use Filament\Navigation\NavigationBuilder;
use Livewire\Livewire;

class DataFeaturesTest extends DynamicDataTestCase
{
    // --- A: Demo-Daten -----------------------------------------------------------------

    public function test_demo_seeder_creates_four_tables_with_records_and_is_repeatable(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class); // zweiter Lauf darf nichts duplizieren

        $this->assertEqualsCanonicalizing(['artikel', 'kunden', 'bestellungen', 'bestellpositionen'], CustomTable::pluck('name')->all());

        // Berechnete Felder der Demo: Gesamtpreis = Summe der Beträge, MWST daraus.
        $order = DynamicRecord::forTableName('bestellungen')->newQuery()->orderBy('id')->firstOrFail();
        $sum = DynamicRecord::forTableName('bestellpositionen')->newQuery()->where('bestellung_id', $order->id)->sum('betrag');
        $this->assertSame(number_format((float) $sum, 2, '.', ''), $order->gesamtpreis);
        $this->assertSame(number_format(round((float) $order->gesamtpreis * 0.081, 2), 2, '.', ''), $order->mwst);

        foreach (['artikel', 'kunden'] as $name) {
            $this->assertSame(10, DynamicRecord::forTableName($name)->newQuery()->count(), $name);
        }

        // Kunden mit 0 bis 4 Bestellungen.
        $this->assertSame(array_sum(DemoDataSeeder::ORDERS_PER_CUSTOMER), DynamicRecord::forTableName('bestellungen')->newQuery()->count());
        $perCustomer = DynamicRecord::forTableName('bestellungen')->newQuery()->get()->countBy('kunde_id')->values()->sort()->values()->all();
        $this->assertSame([1, 1, 1, 2, 2, 3, 3, 4], $perCustomer);

        $positions = DynamicRecord::forTableName('bestellpositionen')->newQuery()->get();
        $this->assertGreaterThanOrEqual(10, $positions->count());
        $orderIds = DynamicRecord::forTableName('bestellungen')->newQuery()->pluck('id')->all();
        $this->assertTrue($positions->every(fn ($p): bool => in_array($p->bestellung_id, $orderIds, true)));

        $this->assertSame([], app(SchemaVerifier::class)->issues());
        $this->assertSame(4, Menu::where('route', DataMenu::ROUTE)->count());

        // Ein Datenmodell-Eintrag am Ende der Gruppe, auch nach dem zweiten Lauf nur einer.
        $model = Menu::where('route', DataModelPage::ROUTE)->sole();
        $customers = Menu::where('route_parameters->table', 'kunden')->sole();
        $this->assertSame($customers->parent_id, $model->parent_id);
        $this->assertSame('Data model', $model->getTranslation('label', 'en'));
        $this->assertSame(['menu' => $model->id], $model->route_parameters);
        $this->assertSame(['artikel', 'bestellpositionen', 'bestellungen', 'kunden'], collect(app(DataModelGraphBuilder::class)->build($model, $this->superAdmin())->tables)->keys()->sort()->values()->all());
    }

    public function test_demo_tables_are_named_in_every_language(): void
    {
        $this->seed(DemoDataSeeder::class);

        $orders = CustomTable::query()->where('name', 'bestellungen')->firstOrFail();
        $status = $orders->fields->firstWhere('name', 'status');

        $this->assertSame(['de' => 'Bestellungen', 'en' => 'Orders', 'fr' => 'Commandes', 'it' => 'Ordini'], $orders->getTranslations('label'));
        $this->assertSame('Order headers; items are listed in the order lines.', $orders->getTranslation('description', 'en'));
        $this->assertSame('Data dell’ordine', $orders->fields->firstWhere('name', 'bestelldatum')->getTranslation('label', 'it'));
        $this->assertSame(['de' => 'Versendet', 'en' => 'Shipped', 'fr' => 'Expédiée', 'it' => 'Spedito'], collect($status->optionsArray()['options'])->firstWhere('value', 'versendet')['label']);

        // Der Menüeintrag folgt dem Tabellennamen in allen Sprachen.
        $entry = Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'bestellungen')->firstOrFail();
        $this->assertSame('Commandes', $entry->getTranslation('label', 'fr'));
    }

    public function test_demo_seeder_adds_missing_languages_without_overwriting_own_names(): void
    {
        // Stand vor Version 1.2: Demo-Tabellen nur auf Deutsch, teils von Hand angepasst.
        $this->seed(DemoDataSeeder::class);
        $customers = CustomTable::query()->where('name', 'kunden')->firstOrFail();
        $customers->forceFill(['label' => ['de' => 'Kunden'], 'description' => ['de' => 'Kundenstamm.']])->save();

        foreach ($customers->fields as $field) {
            $options = $field->optionsArray();

            foreach ((array) ($options['options'] ?? []) as $n => $option) {
                $options['options'][$n]['label'] = $option['value'] === 'CH' ? 'Helvetien' : $option['label']['de'];
            }

            $german = $field->name === 'firma' ? 'Unternehmen' : $field->getTranslation('label', 'de');
            $field->forceFill(['label' => $field->name === 'kundennummer' ? ['de' => $german, 'fr' => 'Réf. client'] : ['de' => $german], 'options' => $options])->save();
        }

        $this->seed(DemoDataSeeder::class);

        $customers = $customers->fresh(['fields']);
        $this->assertSame('Customers', $customers->getTranslation('label', 'en'));
        $this->assertSame('Fichier des clients.', $customers->getTranslation('description', 'fr'));
        $this->assertSame('Réf. client', $customers->fields->firstWhere('name', 'kundennummer')->getTranslation('label', 'fr'), 'Eigene Übersetzung bleibt');
        $this->assertSame('Customer number', $customers->fields->firstWhere('name', 'kundennummer')->getTranslation('label', 'en'));
        $this->assertSame(['de' => 'Unternehmen'], $customers->fields->firstWhere('name', 'firma')->getTranslations('label'), 'Umbenanntes Feld bleibt unberührt');

        $countries = collect($customers->fields->firstWhere('name', 'land')->optionsArray()['options'])->keyBy('value');
        $this->assertSame('Germany', $countries['DE']['label']['en']);
        $this->assertSame('Helvetien', $countries['CH']['label'], 'Geänderter Auswahlwert bleibt unberührt');
        $this->assertSame([], app(SchemaVerifier::class)->issues());

        // Ein weiterer Lauf ändert nichts mehr.
        $before = $customers->fresh(['fields'])->toArray();
        $this->seed(DemoDataSeeder::class);
        $this->assertEquals($before, $customers->fresh(['fields'])->toArray());
    }

    // --- B: Datenrechte in der Rollenverwaltung ----------------------------------------

    public function test_role_data_permission_matrix_saves_without_touching_other_permissions(): void
    {
        $this->actingAs($this->superAdmin());
        $this->projects();
        $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [['name' => 'name', 'type' => 'string']]);

        $role = Role::create(['name' => 'vertrieb']);
        $role->givePermissionTo(['users.view', 'data.kunden.view']);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertFormSet(['data_permissions.kunden' => ['view'], 'data_permissions.projekte' => []])
            ->fillForm(['data_permissions.projekte' => ['view', 'update']])
            ->call('save')
            ->assertHasNoFormErrors();

        $names = $role->fresh()->permissions->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['users.view', 'data.kunden.view', 'data.projekte.view', 'data.projekte.update'], $names);

        // Änderung im Admin-Bereich lässt Datenrechte stehen.
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['permissions' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['data.kunden.view', 'data.projekte.view', 'data.projekte.update'], $role->fresh()->permissions->pluck('name')->all());

        $log = Activity::query()->where('subject_type', Role::class)->where('subject_id', $role->id)->latest('id')->firstOrFail();
        $this->assertContains('data.projekte.update', $log->attribute_changes['old']['permissions']);

        // Wirksamkeit: Benutzer der Rolle darf Projekte bearbeiten, aber nicht löschen.
        $user = User::factory()->create()->assignRole('vertrieb');
        $this->assertTrue($user->can('data.projekte.update'));
        $this->assertFalse($user->can('data.projekte.delete'));
    }

    // --- C: Menütyp Datentabelle -------------------------------------------------------

    public function test_data_menu_entry_with_column_selection(): void
    {
        $this->actingAs($this->superAdmin());
        $this->projects();

        Livewire::test(CreateMenu::class)
            ->fillForm(['type' => 'data', 'data_table' => 'projekte'])
            ->assertFormSet(['label.de' => 'Projekte'])
            ->fillForm(['label.de' => 'Projekte (kompakt)', 'data_columns' => [(string) $this->fieldId('projekte', 'status')]]) // Pflichtfeld titel nicht angehakt
            ->call('create')
            ->assertHasNoFormErrors();

        $menu = Menu::where('label->de', 'Projekte (kompakt)')->firstOrFail();
        $this->assertSame(DataMenu::ROUTE, $menu->route);
        $this->assertSame(['table' => 'projekte'], $menu->route_parameters);
        $this->assertSame('data.projekte.view', $menu->permission_name);
        $this->assertSame([$this->fieldId('projekte', 'titel'), $this->fieldId('projekte', 'status')], $menu->view_config['columns'], 'Pflichtfeld wird ergänzt, Feldreihenfolge bleibt.');

        $item = collect(app(MenuNavigationBuilder::class)->build(new NavigationBuilder, auth()->user())->getNavigation())
            ->flatMap(fn ($g) => $g->getItems())
            ->first(fn ($i) => $i->getLabel() === 'Projekte (kompakt)');
        $this->assertSame(url("/admin/data/projekte?view={$menu->id}"), $item->getUrl());

        Livewire::test(EditMenu::class, ['record' => $menu->getRouteKey()])
            // Pflichtfelder stehen nicht zur Wahl (immer angezeigt) – nur die gewählte Spalte.
            ->assertFormSet(['type' => 'data', 'data_table' => 'projekte', 'data_columns' => [(string) $this->fieldId('projekte', 'status')]]);
    }

    public function test_data_menu_only_offers_active_designer_tables(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->projects();
        $this->service()->updateTable($table, ['is_active' => false]);

        foreach (['projekte', 'users'] as $name) {
            Livewire::test(CreateMenu::class)
                ->fillForm(['type' => 'data', 'label' => 'X', 'data_table' => $name])
                ->call('create')
                ->assertHasFormErrors(['data_table']);
        }
    }

    // --- D: Datenseite -----------------------------------------------------------------

    public function test_view_shows_selected_and_required_columns_only(): void
    {
        $this->actingAs($this->superAdmin());
        $this->projects();
        $menu = Menu::create([
            'label' => 'Kompakt', 'route' => DataMenu::ROUTE, 'route_parameters' => ['table' => 'projekte'],
            'view_config' => ['columns' => [$this->fieldId('projekte', 'budget')]], 'permission_name' => 'data.projekte.view',
        ]);

        $view = Livewire::withQueryParams(['view' => $menu->id])->test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertTableColumnExists('titel')   // Pflicht
            ->assertTableColumnExists('budget')  // gewählt
            ->assertSee('Kompakt');
        // Nicht gewählte Felder gibt es nur eingeklappt in der Spaltenauswahl.
        $this->assertTrue($view->instance()->isTableColumnToggledHidden('anzahl'));
        $this->assertFalse($view->instance()->isTableColumnToggledHidden('budget'));

        // Ohne Ansicht: Designer-Vorgabe (Query-Parameter zurücksetzen).
        Livewire::withQueryParams([])->test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertTableColumnExists('anzahl');
    }

    public function test_view_of_another_table_is_ignored(): void
    {
        $this->actingAs($this->superAdmin());
        $this->projects();
        $this->service()->createTable(['name' => 'kunden'], [['name' => 'name', 'type' => 'string']]);
        $foreign = Menu::create(['label' => 'K', 'route' => DataMenu::ROUTE, 'route_parameters' => ['table' => 'kunden'], 'view_config' => ['columns' => [$this->fieldId('kunden', 'name')]]]);

        Livewire::withQueryParams(['view' => $foreign->id])->test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertTableColumnExists('anzahl');
    }

    public function test_every_column_is_sortable_and_filterable_with_paging(): void
    {
        $this->actingAs($this->superAdmin());
        $model = DynamicRecord::forTable($this->projects());
        $a = $model->create(['titel' => 'Alpha', 'anzahl' => 5]);
        $b = $model->create(['titel' => 'Beta', 'anzahl' => 50]);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            // Aufsteigend: unterscheidet sich von der Standardsortierung (neueste zuerst).
            ->sortTable('anzahl', 'asc')
            ->assertCanSeeTableRecords([$a, $b], inOrder: true)
            ->assertTableFilterExists('titel')
            ->assertTableFilterExists('anzahl')
            ->assertTableFilterExists('budget')
            ->assertTableFilterExists('start');

        // Filter bleiben gespeichert (Sitzung und B-027): jeder Schritt mit frischer Ansicht.
        $this->freshView();
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->filterTable('titel', ['value' => 'alp'])
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);

        $this->freshView();
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->filterTable('anzahl', ['from' => 10, 'until' => 100])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a]);

        $model->newQuery()->whereKey($a->id)->update(['start' => '2026-03-01']);
        $this->freshView();
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->filterTable('start', ['from' => '2026-02-01'])
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);

        $this->assertSame([10, 25, 50, 100], Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])->instance()->getTable()->getPaginationPageOptions());
    }

    /** Neue Sitzung ohne gespeicherten Stand der Ansicht (B-027 speichert ihn sonst über die Sitzung hinaus). */
    private function freshView(): void
    {
        UserTableState::query()->delete();
        $this->flushSession();
    }
}
