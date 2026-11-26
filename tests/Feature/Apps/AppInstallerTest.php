<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\Definition\AppDefinition;
use App\Apps\Definition\AppInstaller;
use App\DynamicData\DataMenu;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\AppManual;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** App installieren, auch als Kopie (B-107, Etappe 4b). */
class AppInstallerTest extends DynamicDataTestCase
{
    private App $sales;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
        $this->sales = self::ensureTestApp();
        $this->sales->update(['name' => ['de' => 'Verkauf'], 'prefix' => 'vk', 'version' => '1.0', 'sort_order' => 1]);

        $service = $this->service();
        $customers = $service->createTable(['app_id' => $this->sales->id, 'name' => 'vk_kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $orders = $service->createTable(['app_id' => $this->sales->id, 'name' => 'vk_bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer', 'validation_rules' => ['max' => 3]],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'vk_kunden', 'display_fields' => ['firma']]],
        ]);
        // „Zählen je“ braucht die Verknüpfung – beim Installieren erst nach ihr anlegen.
        $service->addField($orders->refresh(), ['name' => 'position', 'label' => 'Position', 'type' => 'integer', 'options' => ['suggest_next' => true, 'suggest_scope' => 'kunde_id']]);
        $service->addField($customers->refresh(), ['name' => 'stueck', 'label' => 'Stück', 'type' => 'computed', 'options' => ['formula' => 'sum(vk_bestellungen.menge)', 'result' => 'integer']]);

        $group = Menu::create(['app_id' => $this->sales->id, 'label' => ['de' => 'Verkauf'], 'sort_order' => 10]);
        app(DataMenu::class)->create($customers, $group->id);
        app(DataMenu::class)->create($orders, $group->id);
        $report = Report::query()->create(['name' => 'Umsatz', 'owner_id' => $this->admin->id, 'is_shared' => true, 'definition' => ['base' => $orders->id, 'fields' => [['key' => 'f1', 'path' => [$this->fieldId('vk_bestellungen', 'kunde_id')], 'field' => $this->fieldId('vk_kunden', 'firma')]]]]);
        Menu::create(['parent_id' => $group->id, 'label' => ['de' => 'Umsatz'], 'route' => 'filament.admin.resources.reports.view', 'route_parameters' => ['record' => (string) $report->id], 'sort_order' => 90]);
        ReportLink::query()->create(['label' => ['de' => 'Zur Firma'], 'source_table_id' => $orders->id, 'source_field_id' => $this->fieldId('vk_bestellungen', 'nummer'), 'target_table_id' => $customers->id, 'target_field_id' => $this->fieldId('vk_kunden', 'firma')]);

        $role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->sales->id]);
        $role->givePermissionTo(['reports.view', 'data.vk_kunden.view', 'data.vk_bestellungen.view']);
        $role->hiddenMenus()->attach(Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'vk_bestellungen')->value('id'));
        ScheduledTask::query()->create(['name' => 'Aufräumen', 'type' => 'task_runs_prune', 'app_id' => $this->sales->id, 'frequency' => 'daily', 'parameters' => []]);
        AppManual::query()->create(['app_id' => $this->sales->id, 'locale' => 'de', 'content' => "# Verkauf\n"]);
    }

    private function installer(): AppInstaller
    {
        return app(AppInstaller::class);
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return app(AppDefinition::class)->build($this->sales, AppDefinition::PARTS, $this->admin);
    }

    /** Die App samt Inhalt abbauen (wie in einer leeren Zielinstallation). */
    private function wipe(): void
    {
        $this->sales->scheduledTasks()->delete();
        $this->sales->reports()->get()->each->delete();
        $this->sales->reportLinks()->get()->each->delete();
        Menu::query()->where('app_id', $this->sales->id)->whereNotNull('route')->get()->each->delete();
        Menu::query()->where('app_id', $this->sales->id)->get()->each->delete();
        $this->sales->roles()->get()->each->delete();
        $tables = $this->sales->customTables()->get();
        $tables->each(fn (CustomTable $t) => $t->update(['is_active' => false]));
        $this->service()->dropTables($tables->each->refresh());
        $this->sales->manuals()->delete();
        $this->sales->delete();
    }

    public function test_installs_a_definition_with_its_stable_keys_as_a_deactivated_app(): void
    {
        $definition = $this->definition();
        $this->wipe();

        $analysis = $this->installer()->analyze($definition);
        $this->assertSame([], $analysis->blockers);
        $this->assertSame(['tables' => 2, 'fields' => 6, 'external_tables' => 0, 'report_links' => 1, 'reports' => 1, 'menus' => 4, 'roles' => 1, 'tasks' => 1, 'documents' => 0, 'manuals' => 1], $analysis->counts);

        $app = $this->installer()->install($definition, null, $this->admin);

        $this->assertFalse($app->is_active, 'Zuerst deaktiviert.');
        $this->assertSame($definition['app']['key'], $app->uuid);
        $this->assertSame(['vk', '1.0'], [$app->prefix, $app->version]);
        $orders = CustomTable::query()->where('name', 'vk_bestellungen')->sole();
        $this->assertSame([$definition['tables'][0]['key'], $app->id], [$orders->uuid, $orders->app_id]);
        $this->assertSame(collect($definition['tables'][0]['fields'])->pluck('key', 'name')->sort()->all(), $orders->fields->pluck('uuid', 'name')->sort()->all());
        $this->assertEquals(['max' => 3], $orders->fields->firstWhere('name', 'menge')->validation_rules);
        $this->assertSame('sum(vk_bestellungen.menge)', CustomField::query()->where('name', 'stueck')->sole()->optionsArray()['formula']);
        $this->assertSame('kunde_id', $orders->fields->firstWhere('name', 'position')->optionsArray()['suggest_scope']);

        $report = Report::query()->sole();
        $this->assertSame([$orders->id, $app->id, $this->admin->id], [(int) $report->definition['base'], $report->app_id, $report->owner_id]);
        $menu = Menu::query()->where('route', 'filament.admin.resources.reports.view')->sole();
        $this->assertSame((string) $report->id, $menu->route_parameters['record']);
        $this->assertSame($app->id, ReportLink::query()->sole()->app_id);

        $role = Role::findByName('vertrieb');
        $this->assertSame($app->id, $role->app_id);
        $this->assertEqualsCanonicalizing(['reports.view', 'data.vk_kunden.view', 'data.vk_bestellungen.view'], $role->permissions->pluck('name')->all());
        $this->assertSame(['vk_bestellungen'], $role->hiddenMenus->map(fn (Menu $m): string => $m->route_parameters['table'])->all());
        $this->assertSame('Aufräumen', ScheduledTask::query()->where('app_id', $app->id)->sole()->name);
        $this->assertSame("# Verkauf\n", AppManual::query()->where('app_id', $app->id)->sole()->content);

        $baseline = $app->baseline();
        $this->assertSame([AppBaseline::INSTALLED, '1.0', $this->admin->id], [$baseline->kind, $baseline->version, $baseline->installed_by]);
        $this->assertSame($definition['app']['key'], $baseline->definition['app']['key']);

        // Ein zweites Mal: abgelehnt, mit Hinweis auf die Kopie.
        $blockers = $this->installer()->analyze($definition)->blockers;
        $this->assertContains(__('apps.install.errors.installed', ['app' => 'Verkauf']), $blockers);
        $this->assertContains(__('apps.install.errors.table_exists', ['table' => 'vk_kunden', 'app' => 'Verkauf']), $blockers);
    }

    public function test_number_sequence_settings_travel_without_counters(): void
    {
        $orders = CustomTable::query()->where('name', 'vk_bestellungen')->sole();
        $this->service()->addField($orders, ['name' => 'beleg', 'label' => 'Beleg', 'type' => 'number', 'options' => ['pattern' => 'B-{YYYY}-{000}', 'reset' => 'yearly', 'start' => 100]]);
        DynamicRecord::forTable($orders->fresh())->create(['nummer' => 'x']);

        $definition = $this->definition();
        $this->wipe();
        $this->installer()->install($definition, null, $this->admin);

        $installed = CustomTable::query()->where('name', 'vk_bestellungen')->sole();
        $beleg = $installed->fields->firstWhere('name', 'beleg');
        $this->assertSame(['B-{YYYY}-{000}', 'yearly', 100, true], [$beleg->optionsArray()['pattern'], $beleg->optionsArray()['reset'], $beleg->optionsArray()['start'], $beleg->is_unique]);
        $this->assertSame('B-'.now()->format('Y').'-100', DynamicRecord::forTable($installed)->create(['nummer' => 'y'])->beleg, 'Zähler reisen nicht mit: beginnt beim Startwert.');
    }

    public function test_grouped_number_sequence_installs_after_its_select_field(): void
    {
        $orders = CustomTable::query()->where('name', 'vk_bestellungen')->sole();
        $this->service()->addField($orders, ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [['value' => 'Auftrag'], ['value' => 'Gutschrift']]]]);
        $this->service()->addField($orders->fresh(), ['name' => 'beleg', 'label' => 'Beleg', 'type' => 'number', 'options' => [
            'pattern' => '{GROUP}{#}', 'group_field' => 'art', 'group_codes' => ['Auftrag' => 'A', 'Gutschrift' => 'G'],
        ]]);

        // Im Paket steht der Nummernkreis vor seinem Gruppenfeld.
        $definition = $this->definition();
        $i = array_search('vk_bestellungen', array_column($definition['tables'], 'name'), true);
        usort($definition['tables'][$i]['fields'], static fn (array $a, array $b): int => ($b['name'] === 'beleg') <=> ($a['name'] === 'beleg'));
        $this->wipe();
        $this->installer()->install($definition, null, $this->admin);

        $installed = CustomTable::query()->where('name', 'vk_bestellungen')->sole();
        $this->assertEquals(['Auftrag' => 'A', 'Gutschrift' => 'G'], $installed->fields->firstWhere('name', 'beleg')->optionsArray()['group_codes']);
        $this->assertSame('G1', DynamicRecord::forTable($installed)->create(['nummer' => 'x', 'art' => 'Gutschrift'])->beleg);
    }

    public function test_installs_a_copy_with_renamed_tables_and_new_keys(): void
    {
        $definition = $this->definition();

        $this->assertSame([__('apps.install.errors.copy_same_prefix')], $this->installer()->analyze($definition, ['name' => 'Verkauf 2', 'prefix' => 'vk'])->blockers);
        $this->assertSame([__('apps.install.errors.copy_prefix')], $this->installer()->analyze($definition, ['name' => 'Verkauf 2', 'prefix' => '2x'])->blockers);

        $analysis = $this->installer()->analyze($definition, ['name' => 'Verkauf 2', 'prefix' => 'vk2']);
        $this->assertSame([], $analysis->blockers);
        $this->assertContains(__('apps.install.warnings.role', ['role' => 'vertrieb', 'new' => 'vertrieb-vk2']), $analysis->warnings);

        $copy = $this->installer()->install($definition, ['name' => 'Verkauf 2', 'prefix' => 'vk2'], $this->admin);

        $this->assertSame(['Verkauf 2', 'vk2'], [(string) $copy->name, $copy->prefix]);
        $this->assertNotSame($this->sales->uuid, $copy->uuid);
        $this->assertSame(['vk2_bestellungen', 'vk2_kunden'], $copy->customTables()->orderBy('name')->pluck('name')->all());
        $this->assertSame(2, $this->sales->customTables()->count(), 'Das Original bleibt.');

        $orders = CustomTable::query()->where('name', 'vk2_bestellungen')->sole();
        $this->assertNotSame(CustomTable::query()->where('name', 'vk_bestellungen')->value('uuid'), $orders->uuid);
        $this->assertSame('vk2_kunden', $orders->fields->firstWhere('name', 'kunde_id')->optionsArray()['table']);
        $this->assertSame('sum(vk2_bestellungen.menge)', CustomField::query()->whereHas('custom_table', fn ($q) => $q->where('name', 'vk2_kunden'))->where('name', 'stueck')->sole()->optionsArray()['formula']);

        $report = Report::query()->where('app_id', $copy->id)->sole();
        $this->assertSame($orders->id, (int) $report->definition['base']);
        $this->assertSame((string) $report->id, Menu::query()->where('app_id', $copy->id)->where('route', 'filament.admin.resources.reports.view')->sole()->route_parameters['record'], 'Menü zeigt auf die Auswertung der Kopie.');
        $this->assertSame(1, Menu::query()->where('app_id', $copy->id)->whereNull('route')->count(), 'Eigene Gruppe, nicht die des Originals.');

        $role = Role::findByName('vertrieb-vk2');
        $this->assertSame($copy->id, $role->app_id);
        $this->assertEqualsCanonicalizing(['reports.view', 'data.vk2_kunden.view', 'data.vk2_bestellungen.view'], $role->permissions->pluck('name')->all());
        $this->assertSame(['vk2_bestellungen'], $role->hiddenMenus->map(fn (Menu $m): string => $m->route_parameters['table'])->all());
        $this->assertSame(AppBaseline::COPY, $copy->baseline()->kind);
    }

    public function test_missing_plugins_and_connections_only_warn(): void
    {
        $definition = $this->definition();
        $this->wipe();
        $definition['plugins'] = ['gibt-es-nicht' => '1.0'];
        $definition['external_tables'] = [[
            'key' => (string) Str::uuid(), 'name' => 'vk_erp_artikel', 'label' => ['de' => 'ERP-Artikel'], 'description' => null, 'icon' => null,
            'connection' => 'ERP', 'source' => ['schema' => 'dbo', 'table' => 'Artikel', 'kind' => 'table'], 'key_columns' => ['ArtNr'],
            'allow' => ['create' => false, 'update' => false, 'delete' => false], 'export_enabled' => false,
            'fields' => [['key' => (string) Str::uuid(), 'name' => 'ArtNr', 'label' => ['de' => 'Artikelnummer'], 'type' => 'string', 'external_type' => 'varchar', 'is_read_only' => false, 'is_generated' => false,
                'is_required' => false, 'is_unique' => true, 'is_searchable' => true, 'is_sortable' => false, 'show_in_table' => true, 'show_in_form' => true, 'is_active' => true, 'sort_order' => 1, 'options' => null, 'validation_rules' => null]],
        ]];

        $analysis = $this->installer()->analyze($definition);
        $this->assertSame([], $analysis->blockers);
        $this->assertContains(__('apps.install.warnings.plugin', ['plugin' => 'gibt-es-nicht']), $analysis->warnings);
        $this->assertContains(__('apps.install.warnings.external_unconnected', ['table' => 'vk_erp_artikel', 'connection' => 'ERP']), $analysis->warnings);

        $app = $this->installer()->install($definition, null, $this->admin);

        $external = CustomTable::query()->where('name', 'vk_erp_artikel')->sole();
        $this->assertTrue($external->isExternal());
        $this->assertNull($external->database_connection_id);
        $this->assertFalse($external->is_active);
        $this->assertSame([$app->id, ['ArtNr']], [$external->app_id, $external->keyColumns()]);
        $this->assertSame(['ArtNr'], $external->fields->pluck('name')->all());
        $this->assertSame(['gibt-es-nicht'], $app->baseline()->requiredPlugins());
    }
}
