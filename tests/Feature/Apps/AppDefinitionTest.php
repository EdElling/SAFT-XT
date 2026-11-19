<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\Definition\AppDefinition;
use App\Apps\Definition\AppDefinitionValidator;
use App\DynamicData\DataMenu;
use App\Filament\Pages\AppDataModelPage;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Models\App;
use App\Models\AppManual;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** App-Definition „saft-app/1“ und Export (B-107, Etappe 4a). */
class AppDefinitionTest extends DynamicDataTestCase
{
    private App $sales;

    private App $other;

    private CustomTable $customers;

    private CustomTable $orders;

    private Report $report;

    private Menu $group;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());
        $this->sales = self::ensureTestApp();
        $this->sales->update(['name' => ['de' => 'Verkauf', 'en' => 'Sales'], 'prefix' => 'vk', 'version' => '1.2', 'sort_order' => 1]);
        $this->other = App::query()->create(['name' => ['de' => 'Personal'], 'prefix' => 'hr', 'sort_order' => 2]);

        $service = $this->service();
        $this->customers = $service->createTable(['app_id' => $this->sales->id, 'name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->orders = $service->createTable(['app_id' => $this->sales->id, 'name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer', 'validation_rules' => ['max' => 3]],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['firma']]],
        ]);
        $service->createTable(['app_id' => $this->other->id, 'name' => 'hr_personen', 'label' => 'Personen'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
        ]);

        $this->group = Menu::create(['app_id' => $this->sales->id, 'label' => ['de' => 'Verkauf'], 'sort_order' => 10]);
        app(DataMenu::class)->create($this->customers, $this->group->id);
        app(DataMenu::class)->create($this->orders, $this->group->id);
        $this->report = Report::query()->create(['name' => 'Umsatz', 'owner_id' => auth()->id(), 'is_shared' => true, 'definition' => ['base' => $this->orders->id, 'fields' => []]]);
        Menu::create(['parent_id' => $this->group->id, 'label' => ['de' => 'Umsatz'], 'route' => 'filament.admin.resources.reports.view', 'route_parameters' => ['record' => (string) $this->report->id], 'sort_order' => 90]);
        ReportLink::query()->create(['label' => ['de' => 'Zur Firma'], 'source_table_id' => $this->orders->id, 'source_field_id' => $this->fieldId('bestellungen', 'nummer'), 'target_table_id' => $this->customers->id, 'target_field_id' => $this->fieldId('kunden', 'firma')]);

        $this->role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->sales->id]);
        $this->role->givePermissionTo(['reports.view', 'data.kunden.view', 'data.bestellungen.view']);
        // Aus der Zeit vor den Apps: ausgeblendeter Eintrag einer anderen App (gehört nicht in die Definition).
        $foreign = app(DataMenu::class)->create(CustomTable::query()->where('name', 'hr_personen')->sole(), Menu::create(['app_id' => $this->other->id, 'label' => ['de' => 'Personal']])->id);
        $this->role->hiddenMenus()->attach([$foreign->id, Menu::query()->where('route', DataMenu::ROUTE)->where('app_id', $this->sales->id)->value('id')]);
        ScheduledTask::query()->create(['name' => 'Aufräumen', 'type' => 'task_runs_prune', 'app_id' => $this->sales->id, 'frequency' => 'daily', 'parameters' => []]);
        AppManual::query()->create(['app_id' => $this->sales->id, 'locale' => 'de', 'content' => "# Verkauf\n"]);
    }

    /** @return array<string, mixed> */
    private function definition(array $parts = AppDefinition::PARTS): array
    {
        return app(AppDefinition::class)->build($this->sales, $parts, auth()->user());
    }

    public function test_definition_describes_the_whole_app_with_stable_keys(): void
    {
        $definition = $this->definition();

        $this->assertSame('saft-app/1', $definition['format']);
        $this->assertSame([], app(AppDefinitionValidator::class)->errors($definition));
        $this->assertSame($this->sales->uuid, $definition['app']['key']);
        $this->assertSame(['vk', '1.2'], [$definition['app']['prefix'], $definition['app']['version']]);
        $this->assertSame(['de' => "# Verkauf\n"], $definition['app']['manual']);

        // Tabellen nur dieser App, mit Schlüsseln, Feldern, Regeln und Verknüpfung.
        $this->assertSame(['bestellungen', 'kunden'], array_column($definition['tables'], 'name'));
        $orders = $definition['tables'][0];
        $this->assertSame($this->orders->uuid, $orders['key']);
        $fields = collect($orders['fields'])->keyBy('name');
        $this->assertSame($this->orders->fields->firstWhere('name', 'menge')->uuid, $fields['menge']['key']);
        $this->assertEquals(['max' => 3], $fields['menge']['validation_rules']);
        $this->assertSame('kunden', $fields['kunde_id']['options']['table']);
        $this->assertArrayNotHasKey('permissions', $orders);

        $this->assertSame([['key' => ReportLink::query()->sole()->uuid, 'label' => ['de' => 'Zur Firma'], 'source' => 'bestellungen.nummer', 'target_table' => 'kunden', 'target_field' => 'kunden.firma']], $definition['report_links']);
        $this->assertSame(['Umsatz'], array_column($definition['reports'], 'name'));
        $this->assertSame('bestellungen', $definition['reports'][0]['definition']['base']);
        $this->assertSame(['vertrieb'], array_column($definition['roles'], 'name'));
        $this->assertSame(['data.bestellungen.view', 'data.kunden.view', 'reports.view'], $definition['roles'][0]['permissions']);
        $this->assertSame(['Verkauf › Kunden'], $definition['roles'][0]['hidden_menus'], 'Nur ausgeblendete Einträge dieser App.');
        $this->assertSame(['Aufräumen'], array_column($definition['tasks'], 'name'));

        // Menüs der App (Eltern zuerst), keine Systemmenüs und Systemrollen; nichts aus der anderen App.
        $this->assertSame('Verkauf', $definition['menus'][0]['path']);
        $this->assertContains('Verkauf › Umsatz', array_column($definition['menus'], 'path'));
        $this->assertNotContains('Administration', array_column($definition['menus'], 'path'));
        $json = json_encode($definition, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('hr_personen', $json);
        $this->assertStringNotContainsString(User::ROLE_SUPER_ADMIN, $json);
        $this->assertStringNotContainsString('password', $json);
    }

    public function test_parts_can_be_left_out(): void
    {
        $definition = $this->definition(['roles']);

        $this->assertSame([], $definition['reports']);
        $this->assertSame([], $definition['tasks']);
        $this->assertSame([], $definition['app']['manual']);
        $this->assertNotContains('Verkauf › Umsatz', array_column($definition['menus'], 'path'), 'Menüeintrag auf eine nicht exportierte Auswertung fällt weg.');
        $this->assertSame([], app(AppDefinitionValidator::class)->errors($definition));
    }

    public function test_validator_reports_errors_with_path(): void
    {
        $definition = $this->definition();
        $definition['tables'][0]['fields'][0]['type'] = 'hologramm';
        $definition['tables'][1]['key'] = $definition['tables'][0]['key'];
        $definition['tables'][0]['fields'][2]['options']['table'] = 'hr_personen';
        $definition['roles'][0]['key'] = 'kein-schluessel';

        $errors = app(AppDefinitionValidator::class)->errors($definition);

        $this->assertContains('tables[0].fields[0].type: '.__('apps.definition.errors.type', ['type' => 'hologramm']), $errors);
        $this->assertContains('tables[1].key: '.__('apps.definition.errors.duplicate', ['value' => $definition['tables'][0]['key']]), $errors);
        $this->assertContains('tables[0].fields[2].options.table: '.__('apps.definition.errors.unknown_table', ['table' => 'hr_personen']), $errors);
        $this->assertContains('roles[0].key: '.__('apps.definition.errors.key'), $errors);
        $this->assertSame(['format: '.__('apps.definition.errors.format', ['format' => 'saft-app/1'])], app(AppDefinitionValidator::class)->errors(['format' => 'saft-config/1']));
    }

    public function test_export_downloads_the_definition_and_needs_its_own_permission(): void
    {
        Livewire::test(ListApps::class)
            ->callAction(TestAction::make('exportApp')->table($this->sales), ['parts' => ['roles', 'manual']])
            ->assertFileDownloaded('vk-1.2.saft-app.json');
        $this->assertSame(1, Activity::query()->where('event', 'exported')->where('subject_id', $this->sales->id)->count());

        $this->actingAs($this->userWithPermissions(['apps.view', 'apps.manage']));
        Livewire::test(ListApps::class)->assertActionHidden(TestAction::make('exportApp')->table($this->sales));
    }

    public function test_version_is_kept_and_the_data_model_shows_the_tables_of_the_app(): void
    {
        Livewire::test(EditApp::class, ['record' => $this->sales->getRouteKey()])
            ->fillForm(['version' => '2.0'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('2.0', $this->sales->fresh()->version);
        $this->assertSame('vk-2.0.saft-app.json', AppDefinition::fileName($this->sales->fresh()));

        Livewire::test(AppDataModelPage::class, ['app' => $this->sales->id])
            ->assertSee('Datenmodell: Verkauf')
            ->assertSee('Bestellungen')
            ->assertDontSee('Personen');
    }
}
