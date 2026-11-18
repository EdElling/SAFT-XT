<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Apps\Pages\CreateApp;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Support\AppSelect;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Models\User;
use Database\Seeders\LagerDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Apps (B-107, Etappe 1a): Seite „Apps“, Zuordnung, Grenze zwischen Apps, Rolle kopieren, Umstellung, Demos. */
class AppsTest extends DynamicDataTestCase
{
    private App $first;

    private App $second;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->first = self::ensureTestApp();
        $this->second = App::query()->create(['name' => ['de' => 'Personal', 'en' => 'HR'], 'prefix' => 'hr', 'sort_order' => 2]);
        $this->actingAs($this->superAdmin());
    }

    private function table(App $app, string $name): CustomTable
    {
        return $this->service()->createTable(['app_id' => $app->id, 'name' => $name, 'label' => ucfirst($name)], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
        ]);
    }

    public function test_apps_page_lists_creates_edits_and_deletes_only_empty_apps(): void
    {
        $this->table($this->first, 'kunden');

        Livewire::test(ListApps::class)
            ->assertCanSeeTableRecords([$this->first, $this->second])
            ->assertTableColumnStateSet('custom_tables_count', 1, $this->first)
            ->assertTableColumnStateSet('custom_tables_count', 0, $this->second)
            ->assertTableActionDisabled('delete', $this->first)
            ->assertTableActionEnabled('delete', $this->second);

        Livewire::test(CreateApp::class)
            ->fillForm(['name' => ['de' => 'Projekte', 'en' => 'Projects'], 'prefix' => 'Proj_', 'sort_order' => 3])
            ->call('create')
            ->assertHasNoFormErrors();
        $created = App::query()->where('name->de', 'Projekte')->sole();
        $this->assertSame('proj', $created->prefix);
        $this->assertSame('proj_', $created->tablePrefix());
        $this->assertNotEmpty($created->uuid);

        Livewire::test(EditApp::class, ['record' => $created->id])
            ->assertFormSet(['name.en' => 'Projects'])
            ->fillForm(['name' => ['de' => 'Projektverwaltung']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Projektverwaltung', $created->fresh()->getTranslation('name', 'de'));

        $this->expectException(BusinessRuleException::class);
        $this->first->delete();
    }

    public function test_objects_derive_their_app_and_relations_stay_inside_one_app(): void
    {
        $kunden = $this->table($this->first, 'kunden');
        $staff = $this->table($this->second, 'hr_mitarbeitende');

        // Menüeintrag folgt der Tabelle, Auswertung der Grundtabelle, Aufgabe der Tabelle.
        $entry = app(DataMenu::class)->create($kunden, DataMenu::NEW_DATA_GROUP);
        $this->assertSame($this->first->id, $entry->app_id);
        $this->assertSame($this->first->id, $entry->parent->app_id, 'Gruppe „Daten“ der App.');
        $this->assertSame([(int) $entry->parent_id], array_keys(array_filter(app(DataMenu::class)->groupOptions($this->first->id), static fn (string $label): bool => trim($label) === 'Daten')));
        $this->assertArrayNotHasKey((string) $entry->parent_id, app(DataMenu::class)->groupOptions($this->second->id));

        $report = Report::query()->create(['name' => 'Kunden', 'owner_id' => auth()->id(), 'definition' => ['base' => $kunden->id]]);
        $this->assertSame($this->first->id, $report->app_id);
        $task = ScheduledTask::query()->create(['type' => 'snapshot_refresh', 'parameters' => ['table_id' => $staff->id], 'frequency' => 'daily', 'at_time' => '05:00']);
        $this->assertSame($this->second->id, $task->app_id);

        // Verknüpfung über die App-Grenze: abgelehnt, innerhalb der App möglich.
        try {
            $this->service()->addField($staff, ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['name']]]);
            $this->fail('Verknüpfung über die App-Grenze angelegt.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('nur zu Tabellen derselben App', $e->getMessage());
        }

        $this->service()->addField($kunden, ['name' => 'betreuer_id', 'label' => 'Betreuer', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['name']]]);

        $this->expectException(BusinessRuleException::class);
        ReportLink::query()->create([
            'label' => ['de' => 'Quer'],
            'source_table_id' => $staff->id,
            'source_field_id' => $staff->fields()->where('name', 'name')->value('id'),
            'target_table_id' => $kunden->id,
            'target_field_id' => $kunden->fields()->where('name', 'name')->value('id'),
        ]);
    }

    public function test_new_tables_need_an_app_when_there_are_several(): void
    {
        try {
            $this->service()->createTable(['name' => 'ohne_app', 'label' => 'Ohne App'], [['name' => 'name', 'label' => 'Name', 'type' => 'string']]);
            $this->fail('Tabelle ohne App angelegt.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Bitte eine App wählen.', $e->getMessage());
        }

        // Im Table-Designer: App wählen, Präfix wird dem vorgeschlagenen Namen vorangestellt.
        Livewire::test(CreateCustomTable::class)
            ->fillForm(['app_id' => $this->second->id])
            ->fillForm(['label' => ['de' => 'Abwesenheiten']])
            ->assertFormSet(['name' => 'hr_abwesenheiten'])
            ->fillForm(['fields' => [['label' => ['de' => 'Grund'], 'name' => 'grund', 'type' => 'string', 'options' => ['length' => 100]]], 'menu_group' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->second->id, CustomTable::query()->where('name', 'hr_abwesenheiten')->value('app_id'));
    }

    public function test_role_copy_keeps_permissions_of_the_target_app_only(): void
    {
        $this->table($this->first, 'kunden');
        $this->table($this->second, 'hr_mitarbeitende');
        $role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $role->givePermissionTo(['reports.view', 'data.kunden.view', 'data.hr_mitarbeitende.view']);
        User::factory()->create()->assignRole($role);

        $copy = $role->copyAs('vertrieb-kopie', $this->first->id);
        $this->assertSame($this->first->id, $copy->app_id);
        $this->assertEqualsCanonicalizing(['reports.view', 'data.kunden.view'], $copy->permissions->pluck('name')->all());
        $this->assertSame(0, $copy->users()->count(), 'Ohne Mitglieder.');

        Livewire::test(ListRoles::class)
            ->callAction(TestAction::make('copyRole')->table($role), ['name' => 'personal-lesen', 'app_id' => $this->second->id])
            ->assertHasNoFormErrors();
        $other = Role::findByName('personal-lesen');
        $this->assertSame($this->second->id, $other->app_id);
        $this->assertEqualsCanonicalizing(['reports.view', 'data.hr_mitarbeitende.view'], $other->permissions->pluck('name')->all());
    }

    public function test_migration_puts_existing_objects_into_one_app_and_leaves_system_objects_alone(): void
    {
        $this->table($this->first, 'kunden');
        $role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $group = Menu::query()->create(['app_id' => $this->first->id, 'label' => ['de' => 'Verkauf']]);

        // Stand vor der Umstellung: keine Apps.
        foreach (['custom_tables', 'menus', 'reports', 'report_links', 'roles', 'scheduled_tasks'] as $table) {
            DB::table($table)->update(['app_id' => null]);
        }
        DB::table('apps')->delete();

        (require database_path('migrations/2026_12_27_100200_assign_existing_objects_to_app.php'))->up();

        $app = App::query()->sole();
        $this->assertSame('Bestehende Daten', $app->getTranslation('name', 'de'));
        $this->assertSame($app->id, CustomTable::query()->where('name', 'kunden')->value('app_id'));
        $this->assertSame($app->id, $role->fresh()->app_id);
        $this->assertSame($app->id, $group->fresh()->app_id);
        $this->assertNull(Role::findByName(User::ROLE_SUPER_ADMIN)->app_id);
        $this->assertNull(Role::findByName(User::ROLE_DEFAULT)->app_id);
        $this->assertNull(Menu::query()->whereNull('parent_id')->where('label->de', 'Administration')->value('app_id'), 'Administration bleibt System.');
        $this->assertSame(0, Menu::query()->where('route', 'like', 'filament.admin.resources.%')->whereNotNull('app_id')->count());
        $this->assertSame(0, ScheduledTask::query()->where('is_system', true)->whereNotNull('app_id')->count());
    }

    public function test_migration_creates_no_app_for_a_fresh_installation(): void
    {
        App::query()->each(static fn (App $app) => $app->delete());

        (require database_path('migrations/2026_12_27_100200_assign_existing_objects_to_app.php'))->up();

        $this->assertSame(0, App::query()->count());
    }

    public function test_demo_seeder_creates_its_app_and_is_repeatable(): void
    {
        $this->seed(LagerDemoSeeder::class);
        $lager = App::query()->where('uuid', app(LagerDemoSeeder::class)->demoAppDefinition()['uuid'])->sole();

        $this->assertSame('lager', $lager->prefix);
        $this->assertSame(0, CustomTable::query()->where('name', 'like', 'lager_%')->where('app_id', '!=', $lager->id)->count());
        $this->assertSame($lager->id, Menu::query()->whereNull('parent_id')->where('label->de', 'Lager (Demo)')->value('app_id'));
        $this->assertSame(0, Report::query()->where('app_id', '!=', $lager->id)->count());

        $this->seed(LagerDemoSeeder::class);
        $this->assertSame(1, App::query()->where('uuid', $lager->uuid)->count());
    }

    public function test_prefix_is_suggested_from_the_name_and_system_roles_have_no_data_rights_section(): void
    {
        $this->assertSame('personalhr', AppForm::suggestPrefix('Personal (HR)'));
        $this->assertSame('lagerubersicht', AppForm::suggestPrefix('2 Lager-Übersicht'));

        Livewire::test(CreateApp::class)
            ->fillForm(['name' => ['de' => 'Fuhrpark']])
            ->assertFormSet(['prefix' => 'fuhrpark']);

        Livewire::test(EditRole::class, ['record' => Role::findByName(User::ROLE_DEFAULT)->getRouteKey()])
            ->assertSee(__('roles.form.system_role_hint'))
            ->assertDontSee(__('roles.form.data_permissions_help'));
    }

    public function test_prefix_follows_app_and_name_changes(): void
    {
        $this->assertSame('hr_abwesenheiten', AppSelect::switchPrefix('abwesenheiten', $this->first->id, $this->second->id));
        $this->assertSame('abwesenheiten', AppSelect::switchPrefix('hr_abwesenheiten', $this->second->id, $this->first->id));
        $this->assertSame('hr_abwesenheiten', AppSelect::switchPrefix('hr_abwesenheiten', null, $this->second->id), 'Nicht doppelt.');

        // Tabellenformular: Wechsel der App ersetzt das Präfix der bisherigen App.
        Livewire::test(CreateCustomTable::class)
            ->fillForm(['app_id' => $this->second->id])
            ->fillForm(['label' => ['de' => 'Abwesenheiten']])
            ->assertFormSet(['name' => 'hr_abwesenheiten'])
            ->fillForm(['app_id' => $this->first->id])
            ->assertFormSet(['name' => 'abwesenheiten'])
            ->fillForm(['app_id' => $this->second->id])
            ->assertFormSet(['name' => 'hr_abwesenheiten']);

        // App-Formular: Vorschlag folgt dem Namen, bis das Präfix von Hand geändert wird.
        Livewire::test(CreateApp::class)
            ->fillForm(['name' => ['de' => 'Fuhrpark']])
            ->assertFormSet(['prefix' => 'fuhrpark'])
            ->fillForm(['name' => ['de' => 'Fahrzeuge']])
            ->assertFormSet(['prefix' => 'fahrzeuge'])
            ->fillForm(['prefix' => 'fz'])
            ->fillForm(['name' => ['de' => 'Fahrzeugpark']])
            ->assertFormSet(['prefix' => 'fz']);
    }
}
