<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\AppMover;
use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\CustomTables\Pages\ListCustomTables;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** In andere App verschieben (B-107, Etappe 1b): Prüfung auf den Endzustand, Menüs, Auswertungen, Rollen. */
class AppMoverTest extends DynamicDataTestCase
{
    private App $source;

    private App $target;

    private Menu $sales;

    private Menu $other;

    private Report $report;

    private Role $role;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());
        $this->source = self::ensureTestApp();
        $this->target = App::query()->create(['name' => ['de' => 'Lager'], 'prefix' => 'lager', 'sort_order' => 2]);

        $service = $this->service();
        $customers = $service->createTable(['app_id' => $this->source->id, 'name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $orders = $service->createTable(['app_id' => $this->source->id, 'name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['firma']]],
        ]);
        $lines = $service->createTable(['app_id' => $this->source->id, 'name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string'],
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_fields' => ['nummer']]],
        ]);
        $notes = $service->createTable(['app_id' => $this->source->id, 'name' => 'notizen', 'label' => 'Notizen'], [
            ['name' => 'text', 'label' => 'Text', 'type' => 'string'],
        ]);

        $this->sales = Menu::create(['app_id' => $this->source->id, 'label' => ['de' => 'Verkauf'], 'sort_order' => 10]);
        $this->other = Menu::create(['app_id' => $this->source->id, 'label' => ['de' => 'Sonstiges'], 'sort_order' => 20]);
        $menu = app(DataMenu::class);
        foreach ([$customers, $orders, $lines] as $table) {
            $menu->create($table, $this->sales->id);
        }
        $menu->ensureDataModel($this->sales);
        $menu->create($notes, $this->other->id);

        $this->report = Report::query()->create(['name' => 'Umsatz', 'owner_id' => auth()->id(), 'is_shared' => true, 'definition' => [
            'base' => $orders->id,
            'fields' => [['key' => 'k', 'path' => [$this->fieldId('bestellungen', 'kunde_id')], 'field' => $this->fieldId('kunden', 'firma')]],
        ]]);
        Menu::create(['parent_id' => $this->sales->id, 'label' => ['de' => 'Umsatz'], 'route' => 'filament.admin.resources.reports.view', 'route_parameters' => ['record' => (string) $this->report->id], 'sort_order' => 90]);
        ReportLink::query()->create([
            'label' => ['de' => 'Zum Kunden'], 'source_table_id' => $lines->id, 'source_field_id' => $this->fieldId('positionen', 'artikel'),
            'target_table_id' => $customers->id, 'target_field_id' => $this->fieldId('kunden', 'firma'),
        ]);

        $this->role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->source->id]);
        $this->role->givePermissionTo(['reports.view', 'data.kunden.view', 'data.bestellungen.view', 'data.bestellungen.update', 'data.positionen.view', 'data.notizen.view']);
        $this->member = User::factory()->create();
        $this->member->assignRole($this->role);
    }

    private function mover(): AppMover
    {
        return app(AppMover::class);
    }

    public function test_plan_names_references_across_the_boundary_and_suggests_connected_tables(): void
    {
        $plan = $this->mover()->plan(['bestellungen'], $this->target);

        $this->assertNotSame([], $plan['blockers']);
        $this->assertContains('Verknüpfung „Kunde“ von Bestellungen zu Kunden', $plan['blockers']);
        $this->assertContains('Auswertung „Umsatz“ verwendet Tabellen beider Apps', $plan['blockers']);
        $this->assertEqualsCanonicalizing(['kunden', 'positionen'], $plan['suggested']);
        $this->assertSame(['bestellungen', 'kunden', 'positionen'], $this->mover()->connected(['bestellungen'], $this->target));

        $complete = $this->mover()->plan(['bestellungen', 'kunden', 'positionen'], $this->target);
        $this->assertSame([], $complete['blockers'], 'Verweise innerhalb der Auswahl sind in Ordnung.');
        $this->assertSame(['Umsatz'], $complete['reports']);
        $this->assertSame(3, $complete['menus']);
        $this->assertSame(['name' => 'vertrieb', 'permissions' => 4, 'users' => 1], $complete['roles'][$this->role->id]);

        $this->expectException(BusinessRuleException::class);
        $this->mover()->move(['bestellungen'], $this->target, AppMover::GROUP_NEW, []);
    }

    public function test_moving_connected_tables_takes_menus_reports_links_and_role_rights_along(): void
    {
        $this->mover()->move(['bestellungen', 'kunden', 'positionen'], $this->target, AppMover::GROUP_NEW, [$this->role->id => AppMover::ROLE_NEW], user: auth()->user());

        $this->assertSame(3, CustomTable::query()->where('app_id', $this->target->id)->count());
        $this->assertSame($this->target->id, $this->report->fresh()->app_id);
        $this->assertSame($this->target->id, ReportLink::query()->sole()->app_id);

        // Neue Gruppe „Verkauf“ in der Ziel-App mit allen Einträgen und Datenmodell; die alte, nun leere, ist weg.
        $group = Menu::query()->whereNull('parent_id')->where('app_id', $this->target->id)->where('label->de', 'Verkauf')->sole();
        $this->assertSame(5, Menu::query()->where('parent_id', $group->id)->count());
        $this->assertSame(1, Menu::query()->where('parent_id', $group->id)->where('route', DataModelPage::ROUTE)->count());
        $this->assertSame(0, Menu::query()->where('parent_id', $group->id)->where('app_id', '!=', $this->target->id)->count());
        $this->assertNull($this->sales->fresh());
        $this->assertNotNull($this->other->fresh(), 'Gruppe mit anderen Einträgen bleibt.');

        // Rollen: neue Rolle mit den Rechten und Mitgliedern; die alte behält nur, was nicht verschoben wurde.
        $new = Role::findByName('vertrieb-lager');
        $this->assertSame($this->target->id, $new->app_id);
        $this->assertEqualsCanonicalizing(['data.kunden.view', 'data.bestellungen.view', 'data.bestellungen.update', 'data.positionen.view'], $new->permissions->pluck('name')->all());
        $this->assertTrue($this->member->fresh()->hasRole('vertrieb-lager'));
        $this->assertEqualsCanonicalizing(['reports.view', 'data.notizen.view'], $this->role->fresh()->permissions->pluck('name')->all());
        $this->assertTrue($this->member->fresh()->can('data.bestellungen.update'), 'Niemand verliert den Zugriff.');

        $log = Activity::query()->where('event', 'tables_moved')->sole();
        $this->assertSame(['bestellungen', 'kunden', 'positionen'], $log->attribute_changes['attributes']['tabellen']);
    }

    public function test_a_whole_menu_group_moves_with_its_tables(): void
    {
        $this->mover()->move(['notizen'], $this->target, null, [$this->role->id => AppMover::ROLE_DROP], movedGroup: $this->other);

        $this->assertSame($this->target->id, CustomTable::query()->where('name', 'notizen')->value('app_id'));
        $this->assertSame($this->target->id, $this->other->fresh()->app_id);
        $this->assertSame([$this->target->id], Menu::query()->where('parent_id', $this->other->id)->pluck('app_id')->unique()->values()->all());
        $this->assertFalse($this->role->fresh()->hasPermissionTo('data.notizen.view'), 'Rechte entfernt.');
        $this->assertSame(0, Role::query()->where('app_id', $this->target->id)->count());
    }

    public function test_rights_can_go_to_an_existing_role_of_the_target_app(): void
    {
        $existing = Role::query()->create(['name' => 'lager-team', 'guard_name' => 'web', 'app_id' => $this->target->id]);
        $group = Menu::create(['app_id' => $this->target->id, 'label' => ['de' => 'Ablage']]);

        $this->mover()->move(['notizen'], $this->target, $group->id, [$this->role->id => (string) $existing->id]);

        $this->assertTrue($existing->fresh()->hasPermissionTo('data.notizen.view'));
        $this->assertTrue($this->member->fresh()->hasRole('lager-team'));
        $this->assertSame($group->id, Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'notizen')->value('parent_id'));
        $this->assertNull($this->other->fresh(), 'Leere Gruppe entfernt.');
    }

    public function test_dialog_from_table_list_menu_group_and_apps_page(): void
    {
        $tables = CustomTable::query()->whereIn('name', ['bestellungen', 'kunden', 'positionen'])->get();

        // Table-Designer: Mehrfachauswahl; mit nur einer Tabelle blockiert, „Verknüpfte Tabellen hinzufügen“ ergänzt.
        Livewire::test(ListCustomTables::class)
            ->callTableBulkAction('moveToApp', $tables->where('name', 'bestellungen'), data: ['target' => (string) $this->target->id, 'group' => AppMover::GROUP_NEW])
            ->assertNotified();
        $this->assertSame(0, CustomTable::query()->where('app_id', $this->target->id)->count(), 'Blockiert: nichts verschoben.');

        Livewire::test(ListCustomTables::class)
            ->callTableBulkAction('moveToApp', $tables, data: ['target' => (string) $this->target->id, 'group' => AppMover::GROUP_NEW, 'roles' => [$this->role->id => AppMover::ROLE_NEW], 'role_names' => [$this->role->id => 'vertrieb-neu']])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('3 Tabellen nach „Lager“ verschoben.');
        $this->assertSame(3, CustomTable::query()->where('app_id', $this->target->id)->count());
        $this->assertSame($this->target->id, Role::findByName('vertrieb-neu')->app_id);

        // Menüs: ganze Gruppe.
        Livewire::test(ListMenus::class)
            ->callTableAction('moveGroupToApp', $this->other, data: ['target' => (string) $this->target->id, 'roles' => [$this->role->id => AppMover::ROLE_DROP]])
            ->assertHasNoTableActionErrors();
        $this->assertSame($this->target->id, $this->other->fresh()->app_id);

        // Seite „Apps“: zurück in die Quell-App, Tabelle gewählt.
        Livewire::test(ListApps::class)
            ->callTableAction('moveTablesOfApp', $this->target, data: ['tables' => ['notizen'], 'target' => (string) $this->source->id, 'group' => AppMover::GROUP_NEW])
            ->assertHasNoTableActionErrors();
        $this->assertSame($this->source->id, CustomTable::query()->where('name', 'notizen')->value('app_id'));
    }

    public function test_moving_tables_takes_subgroups_of_their_group_along(): void
    {
        // Gruppe „Verkauf“ mit Untergruppe „Stammdaten“, darin Kunden.
        $sub = Menu::create(['parent_id' => $this->sales->id, 'label' => ['de' => 'Stammdaten'], 'sort_order' => 5]);
        Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->update(['parent_id' => $sub->id]);

        $this->mover()->move(['bestellungen', 'kunden', 'positionen'], $this->target, null, [$this->role->id => AppMover::ROLE_DROP], movedGroup: $this->sales->fresh());

        $this->assertSame($this->target->id, $this->sales->fresh()->app_id);
        $this->assertSame($this->target->id, $sub->fresh()->app_id, 'Untergruppe zieht mit.');
        $this->assertSame([$this->target->id], Menu::query()->where('parent_id', $sub->id)->pluck('app_id')->unique()->values()->all());
    }

    public function test_new_app_of_a_group_and_moving_into_an_existing_group_cover_subgroups(): void
    {
        // Wie in der Praxis: Gruppe im Menüformular einer App zuordnen, dann die Tabellen in diese Gruppe verschieben.
        $sub = Menu::create(['parent_id' => $this->sales->id, 'label' => ['de' => 'Stammdaten'], 'sort_order' => 5]);
        Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->update(['parent_id' => $sub->id]);

        $this->sales->fresh()->update(['app_id' => $this->target->id]);
        $this->assertSame($this->target->id, $sub->fresh()->app_id, 'Untergruppe folgt der Gruppe.');
        $this->assertSame($this->source->id, Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->value('app_id'), 'Dateneintrag folgt seiner Tabelle.');

        $sub->update(['app_id' => $this->source->id]);
        $this->mover()->move(['bestellungen', 'kunden', 'positionen'], $this->target, $this->sales->id, [$this->role->id => AppMover::ROLE_DROP]);

        $this->assertSame($this->target->id, $sub->fresh()->app_id, 'Untergruppe der Zielgruppe gehört zur Ziel-App.');
        $this->assertSame($this->target->id, Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden')->value('app_id'));
    }
}
