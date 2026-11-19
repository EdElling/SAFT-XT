<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\AppPermissions;
use App\Apps\CurrentApp;
use App\Dashboard\DashboardLayout;
use App\Documentation\Documentation;
use App\DynamicData\DataMenu;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Help\KnowledgeSources;
use App\Models\App;
use App\Models\AppManual;
use App\Models\CustomTable;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use Filament\Navigation\NavigationBuilder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Aktuelle App (B-107, Etappe 2): Zugang, Wechsel, Navigation, Dashboard, Auswertungen, Handbücher. */
class CurrentAppTest extends DynamicDataTestCase
{
    private App $sales;

    private App $hr;

    private CustomTable $customers;

    private CustomTable $staff;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->sales = self::ensureTestApp();
        $this->sales->update(['name' => ['de' => 'Verkauf'], 'sort_order' => 1]);
        $this->hr = App::query()->create(['name' => ['de' => 'Personal'], 'prefix' => 'hr', 'sort_order' => 2]);

        $this->customers = $this->service()->createTable(['app_id' => $this->sales->id, 'name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->staff = $this->service()->createTable(['app_id' => $this->hr->id, 'name' => 'hr_mitarbeitende', 'label' => 'Mitarbeitende'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
        ]);
        app(DataMenu::class)->ensure($this->customers);
        app(DataMenu::class)->ensure($this->staff);

        $salesRole = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->sales->id]);
        $salesRole->givePermissionTo(['data.kunden.view', 'reports.view', 'reports.create']);
        $hrRole = Role::query()->create(['name' => 'personal', 'guard_name' => 'web', 'app_id' => $this->hr->id]);
        $hrRole->givePermissionTo(['data.hr_mitarbeitende.view', 'reports.view']);
        $this->person = User::factory()->create();
        $this->person->assignRole([$salesRole, $hrRole, User::ROLE_DEFAULT]);
    }

    private function current(): CurrentApp
    {
        return app(CurrentApp::class);
    }

    /** @return list<string> Bezeichnungen der Gruppen und Einträge der Navigation */
    private function navigation(User $user): array
    {
        $builder = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user);
        $labels = [];

        foreach ($builder->getNavigation() as $group) {
            $labels[] = (string) $group->getLabel();

            foreach ($group->getItems() as $item) {
                $labels[] = strip_tags((string) $item->getLabel());
            }
        }

        return $labels;
    }

    public function test_apps_come_from_roles_and_the_choice_is_remembered(): void
    {
        $this->assertSame(['Verkauf', 'Personal'], $this->current()->accessible($this->person)->map(fn (App $a): string => (string) $a->name)->all());
        $this->assertSame($this->sales->id, $this->current()->id($this->person), 'Ohne Wahl die erste.');

        $this->actingAs($this->person)->post(route('apps.switch', ['app' => $this->hr->id]))->assertRedirect(Dashboard::getUrl());
        $this->assertSame($this->hr->id, $this->person->fresh()->current_app_id);
        $this->assertSame($this->hr->id, app(CurrentApp::class)->id($this->person->fresh()));

        // Ohne Zugang kein Wechsel; der Super-Admin sieht alle Apps.
        $other = App::query()->create(['name' => ['de' => 'Fremd'], 'sort_order' => 3]);
        $this->post(route('apps.switch', ['app' => $other->id]))->assertNotFound();
        $this->assertCount(3, $this->current()->accessible($this->superAdmin()));
        $this->assertSame(0, $this->current()->accessible(User::factory()->create()->assignRole(User::ROLE_DEFAULT))->count(), 'Nur Systemrolle: keine App.');
    }

    public function test_navigation_shows_system_entries_and_the_current_app_only(): void
    {
        $navigation = $this->navigation($this->person);
        $this->assertContains('Kunden', $navigation);
        $this->assertNotContains('Mitarbeitende', $navigation);
        $this->assertContains('Dokumentation', $navigation);

        $this->current()->switch($this->person, $this->hr);
        $navigation = $this->navigation($this->person);
        $this->assertContains('Mitarbeitende', $navigation);
        $this->assertNotContains('Kunden', $navigation);
    }

    public function test_a_link_to_a_table_of_another_app_switches_to_it(): void
    {
        $this->actingAs($this->person);
        $this->assertSame($this->sales->id, $this->current()->id($this->person));

        Livewire::test(DynamicDataPage::class, ['table' => 'hr_mitarbeitende']);

        $this->assertSame($this->hr->id, $this->person->fresh()->current_app_id);
    }

    public function test_dashboard_layout_and_report_cards_are_kept_per_app(): void
    {
        $this->assertSame(['left' => ['A']], DashboardLayout::forApp(['left' => ['A']], '5'), 'Frühere Anordnung gilt als Vorgabe.');
        $stored = DashboardLayout::withApp(['left' => ['A']], '5', ['left' => ['B']]);
        $this->assertSame(['left' => ['B']], DashboardLayout::forApp($stored, '5'));
        $this->assertSame(['left' => ['A']], DashboardLayout::forApp($stored, '6'));

        $salesReport = Report::query()->create(['name' => 'Kundenliste', 'owner_id' => $this->person->id, 'definition' => ['base' => $this->customers->id]]);
        $hrReport = Report::query()->create(['name' => 'Personalliste', 'owner_id' => $this->person->id, 'definition' => ['base' => $this->staff->id]]);
        $this->person->givePermissionTo('widgets.reports');
        $this->person->dashboardReports()->attach([$salesReport->id, $hrReport->id]);
        $this->actingAs($this->person);

        $layout = Livewire::test(Dashboard::class)->instance()->layout();
        $all = [...$layout['left'], ...$layout['right'], ...$layout['full']];
        $this->assertContains('report:'.$salesReport->id, $all);
        $this->assertNotContains('report:'.$hrReport->id, $all);
    }

    public function test_report_permissions_and_list_follow_the_app(): void
    {
        $this->assertTrue(AppPermissions::can($this->person, 'reports.create', $this->sales->id));
        $this->assertFalse(AppPermissions::can($this->person, 'reports.create', $this->hr->id), 'Recht nur über die Rolle der App Verkauf.');
        $this->assertTrue(AppPermissions::can($this->superAdmin(), 'reports.delete', $this->hr->id));

        $salesReport = Report::query()->create(['name' => 'Kundenliste', 'owner_id' => $this->person->id, 'is_shared' => true, 'definition' => ['base' => $this->customers->id]]);
        $hrReport = Report::query()->create(['name' => 'Personalliste', 'owner_id' => $this->person->id, 'is_shared' => true, 'definition' => ['base' => $this->staff->id]]);
        $this->actingAs($this->person);

        $this->assertTrue($this->person->can('create', Report::class));
        $this->assertTrue($this->person->can('update', $salesReport));
        $this->assertFalse($this->person->can('update', $hrReport), 'Eigene Auswertung, aber ohne „anlegen“ in Personal.');

        Livewire::test(ListReports::class)->assertCanSeeTableRecords([$salesReport])->assertCanNotSeeTableRecords([$hrReport]);
        $this->current()->switch($this->person, $this->hr);
        Livewire::test(ListReports::class)->assertCanSeeTableRecords([$hrReport])->assertCanNotSeeTableRecords([$salesReport]);
        $this->assertFalse($this->person->fresh()->can('create', Report::class));
    }

    public function test_app_manual_is_uploaded_and_shown_only_in_its_app(): void
    {
        $this->actingAs($this->superAdmin());
        Livewire::test(ListApps::class)
            ->callTableAction('manual', $this->hr, data: ['files' => ['de' => [UploadedFile::fake()->createWithContent('hr.md', "# Personal\n\n## Abwesenheiten erfassen\n\nSo geht es.\n")]]])
            ->assertHasNoTableActionErrors();
        $this->assertSame(1, AppManual::query()->where('app_id', $this->hr->id)->count());

        $key = Documentation::APP_PREFIX.$this->hr->id;
        $this->assertNotContains($key, Documentation::visibleFor($this->person), 'Aktuelle App ist Verkauf.');
        $this->current()->switch($this->person, $this->hr);
        $this->assertContains($key, Documentation::visibleFor($this->person));
        $this->assertSame('Handbuch Personal', Documentation::label($key));
        $this->assertSame(['Abwesenheiten erfassen'], array_column(Documentation::sections($key, [$key]), 'title'));

        $sources = array_map(static fn ($s): string => $s->id(), app(KnowledgeSources::class)->for($this->person));
        $this->assertContains($key, $sources, 'Hilfe-Assistent kennt das Handbuch der aktuellen App.');

        // Entfernen.
        Livewire::test(ListApps::class)->callTableAction('manual', $this->hr, data: ['remove' => ['de' => true]]);
        $this->assertSame(0, AppManual::query()->count());
    }
}
