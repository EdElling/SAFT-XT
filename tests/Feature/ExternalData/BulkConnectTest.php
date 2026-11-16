<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\DataPermissions;
use App\DynamicData\DetailRelations;
use App\DynamicData\Schema\FieldType;
use App\ExternalData\BulkConnector;
use App\ExternalData\TableConnectorService;
use App\Filament\Resources\DatabaseConnections\Pages\EditDatabaseConnection;
use App\Livewire\BulkConnectTables;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\Menu;
use App\Models\Role;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Massenverknüpfung: viele externe Tabellen auf einmal anbinden (Reiter und Konsolenbefehl). */
class BulkConnectTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $this->connection = $this->researchConnection(['is_read_only' => false, 'last_test_successful' => true]);
    }

    private function bulk(): BulkConnector
    {
        return app(BulkConnector::class);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, array{table: string, name: string, status: string, message: string}>
     */
    private function connect(array $tables, array $options = []): array
    {
        $results = $this->bulk()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $tables, $options);
        $this->bulk()->finish($this->connection);

        return collect($results)->keyBy('table')->all();
    }

    public function test_names_are_normalised_with_prefix(): void
    {
        $this->assertSame('erp_kunden', BulkConnector::proposedName('Kunden', 'erp_'));
        $this->assertSame('tbl_order_items', BulkConnector::proposedName('tblOrder Items'));
        $this->assertSame('tbl_order_items', BulkConnector::proposedName('tbl-Order  Items'));
        $this->assertSame('t_2024_umsatz', BulkConnector::proposedName('2024_Umsatz'));
        $this->assertSame('orders', BulkConnector::proposedName('ORDERS'));
        $this->assertSame(63, strlen(BulkConnector::proposedName(str_repeat('a', 80))));
    }

    public function test_candidates_list_tables_views_keys_and_existing_links(): void
    {
        app(TableConnectorService::class)->connect($this->connection, ExternalResearchDatabase::SCHEMA, 'laender', ['name' => 'laender']);

        $rows = $this->bulk()->candidates($this->connection, ExternalResearchDatabase::SCHEMA, 'f_');

        $this->assertSame('view', $rows['projekt_uebersicht']['kind']);
        $this->assertSame('publikation_id, person_id', $rows['publikation_autoren']['keys']);
        $this->assertSame('f_personen', $rows['personen']['proposed']);
        $this->assertSame('laender', $rows['laender']['connected']);
        $this->assertNull($rows['personen']['connected']);
    }

    public function test_selected_tables_get_settings_relations_menu_and_role_rights_where_possible(): void
    {
        $group = Menu::create(['label' => 'Forschung', 'sort_order' => 50, 'is_active' => true]);
        $role = Role::create(['name' => 'forschende']);

        $results = $this->connect(['organisationen', 'personen', 'projekte', 'projekt_uebersicht'], [
            'prefix' => 'f_',
            'is_active' => true,
            'export_enabled' => true,
            'allow_create' => true,
            'allow_update' => true,
            'allow_delete' => false,
            'menu_group' => (string) $group->id,
            'roles' => [$role->id],
        ]);

        $this->assertSame(BulkConnector::CONNECTED, $results['personen']['status']);
        $this->assertSame(BulkConnector::READ_ONLY, $results['projekt_uebersicht']['status'], 'Views bleiben nur lesbar.');

        $people = CustomTable::query()->where('name', 'f_personen')->with('fields')->sole();
        $this->assertTrue($people->is_active && $people->export_enabled && $people->allow_create && $people->allow_update);
        $this->assertFalse((bool) $people->allow_delete);
        $this->assertSame(['id'], $people->keyColumns());

        // Verknüpfungen zwischen den neu angebundenen Tabellen (einmal am Ende ermittelt).
        $employer = $people->fields->firstWhere('name', 'arbeitgeber_id');
        $this->assertSame(FieldType::Relation, $employer->type);
        $this->assertSame('f_organisationen', $employer->optionsArray()['table']);

        // CHECK-Regel als Werteliste.
        $type = CustomTable::query()->where('name', 'f_organisationen')->sole()->fields()->where('name', 'typ')->sole();
        $this->assertContains('hochschule', $type->optionsArray()['db_values'] ?? []);

        $this->assertSame(4, Menu::query()->where('parent_id', $group->id)->count());

        $role = $role->fresh();
        $this->assertTrue($role->hasPermissionTo(DataPermissions::name('f_personen', 'view')));
        $this->assertTrue($role->hasPermissionTo(DataPermissions::name('f_personen', 'create')));
        $this->assertTrue($role->hasPermissionTo(DataPermissions::name('f_personen', 'export')));
        $this->assertFalse($role->hasPermissionTo(DataPermissions::name('f_personen', 'delete')));
        $this->assertTrue($role->hasPermissionTo(DataPermissions::name('f_projekt_uebersicht', 'view')));
        $this->assertFalse($role->hasPermissionTo(DataPermissions::name('f_projekt_uebersicht', 'create')), 'Nur lesbar: kein Schreibrecht.');
    }

    private function fieldId(string $table, string $field): int
    {
        return (int) CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $field)->value('id');
    }

    /** @return list<string> Details einer Tabelle als „tabelle.feld“ */
    private function details(string $table): array
    {
        return DetailRelations::configuredFor(CustomTable::query()->where('name', $table)->sole())
            ->map(fn ($f): string => $f->child->name.'.'.$f->column())
            ->all();
    }

    public function test_master_detail_is_set_up_from_foreign_keys_for_newly_linked_tables(): void
    {
        $tables = ['organisationen', 'personen', 'projekte', 'publikationen', 'publikation_autoren'];
        $this->bulk()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $tables, []);
        $result = $this->bulk()->finish($this->connection, $tables, details: true);

        // Sortiert nach Bezeichnung der Detailtabelle.
        $this->assertSame(['personen.arbeitgeber_id', 'projekte.partner_id'], $this->details('organisationen'));
        $this->assertSame(['projekte.projektleiter_id', 'publikation_autoren.person_id'], $this->details('personen'));
        $this->assertSame(['publikationen.projekt_id'], $this->details('projekte'));
        $this->assertSame(['publikation_autoren.publikation_id'], $this->details('publikationen'));
        $this->assertSame([], $this->details('publikation_autoren'), 'Auf die Zuordnungstabelle verweist niemand.');
        $this->assertSame(6, $result->detailCount());
        $this->assertSame([], $result->tooMany);
    }

    public function test_existing_details_stay_and_the_switch_can_be_turned_off(): void
    {
        $this->bulk()->connect($this->connection, ExternalResearchDatabase::SCHEMA, ['personen', 'projekte'], []);
        $this->assertSame(0, $this->bulk()->finish($this->connection, ['personen', 'projekte'], details: false)->detailCount());
        $this->assertSame([], $this->details('personen'));

        // Eigene Auswahl: nur die Projekte; die neue Zuordnungstabelle kommt nicht automatisch dazu.
        $people = CustomTable::query()->where('name', 'personen')->sole();
        app(TableConnectorService::class)->update($people, ['detail_field_ids' => [$this->fieldId('projekte', 'projektleiter_id')]]);
        $this->bulk()->connect($this->connection, ExternalResearchDatabase::SCHEMA, ['publikation_autoren'], []);
        $this->bulk()->finish($this->connection, ['personen', 'publikation_autoren'], details: true);

        $this->assertSame(['projekte.projektleiter_id'], $this->details('personen'));
    }

    public function test_tables_with_too_many_references_get_no_details(): void
    {
        $tables = ['organisationen', 'personen', 'projekte'];
        $this->bulk()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $tables, []);
        $result = $this->bulk()->limitDetails(1)->finish($this->connection, $tables, details: true);

        $this->assertSame(['organisationen' => 2], $result->tooMany);
        $this->assertSame([], $this->details('organisationen'));
        $this->assertSame(['projekte.projektleiter_id'], $this->details('personen'));
    }

    public function test_conflicts_are_skipped_and_reported_and_the_rest_continues(): void
    {
        $this->connect(['personen']);

        $results = $this->connect(['personen', 'organisationen'], ['prefix' => '']);
        $this->assertSame(BulkConnector::SKIPPED, $results['personen']['status']);
        $this->assertStringContainsString('personen', $results['personen']['message']);
        $this->assertSame(BulkConnector::CONNECTED, $results['organisationen']['status']);

        // Name belegt (andere Verbindung bzw. Designer-Tabelle).
        CustomTable::query()->where('name', 'organisationen')->sole();
        $second = $this->researchConnection(['name' => 'Zweite', 'is_read_only' => false]);
        $results = collect($this->bulk()->connect($second, ExternalResearchDatabase::SCHEMA, ['organisationen'], []))->keyBy('table');
        $this->assertSame(BulkConnector::SKIPPED, $results['organisationen']['status']);
        $this->assertStringContainsString('organisationen', $results['organisationen']['message']);
    }

    public function test_read_only_connection_links_everything_read_only(): void
    {
        $this->connection->update(['is_read_only' => true]);

        $results = $this->connect(['personen'], ['allow_create' => true]);

        $this->assertSame(BulkConnector::READ_ONLY, $results['personen']['status']);
        $this->assertFalse((bool) CustomTable::query()->where('name', 'personen')->sole()->allow_create);
    }

    public function test_console_command_links_all_open_tables(): void
    {
        $this->artisan('table-connector:connect', ['connection' => 'Forschung', '--all' => true, '--prefix' => 'k_', '--menu-group' => 'none', '--export' => true])
            ->expectsOutputToContain('angebunden')
            ->expectsOutputToContain('Detailtabellen eingerichtet')
            ->assertSuccessful();

        $this->assertSame(7, CustomTable::query()->where('name', 'like', 'k\\_%')->count());
        $this->assertTrue(CustomTable::query()->where('name', 'k_personen')->sole()->export_enabled);
        $this->assertSame(['k_projekte.projektleiter_id', 'k_publikation_autoren.person_id'], $this->details('k_personen'));

        $this->artisan('table-connector:connect', ['connection' => 'Forschung', '--table' => ['gibtsnicht']])->assertFailed();
    }

    public function test_console_command_can_skip_details(): void
    {
        $this->artisan('table-connector:connect', ['connection' => 'Forschung', '--table' => ['personen', 'projekte'], '--prefix' => 'n_', '--menu-group' => 'none', '--no-details' => true])
            ->doesntExpectOutputToContain('Detailtabellen eingerichtet')
            ->assertSuccessful();

        $this->assertSame([], $this->details('n_personen'));
    }

    public function test_tab_requires_a_successful_test_and_links_in_portions(): void
    {
        $this->actingAs($this->superAdmin());

        $this->connection->update(['host' => '127.0.0.1']);
        $this->connection->forceFill(['last_test_successful' => null])->save();
        Livewire::test(EditDatabaseConnection::class, ['record' => $this->connection->getKey()])
            ->assertSee(__('db_connections.tabs.bulk'))
            ->assertSee(__('db_connections.tabs.bulk_untested'));

        $this->connection->forceFill(['last_test_successful' => true])->save();

        $component = Livewire::test(BulkConnectTables::class, ['connectionId' => $this->connection->id])
            ->assertCanSeeTableRecords(['personen', 'organisationen'])
            ->callTableBulkAction('bulkConnect', ['personen', 'organisationen'], ['prefix' => 'w_', 'is_active' => true, 'menu_group' => ''])
            ->assertHasNoTableBulkActionErrors()
            ->assertSet('total', 2);

        $component->call('processNext')->assertSet('queue', []);
        $this->assertSame([BulkConnector::CONNECTED, BulkConnector::CONNECTED], array_column($component->get('results'), 'status'));
        $this->assertSame('w_organisationen', CustomTable::query()->where('name', 'w_personen')->sole()->fields()->where('name', 'arbeitgeber_id')->sole()->optionsArray()['table']);
    }

    public function test_tab_is_not_offered_without_the_right(): void
    {
        $this->actingAs($this->userWithPermissions(['database_connections.view', 'database_connections.update']));

        Livewire::test(BulkConnectTables::class, ['connectionId' => $this->connection->id])->assertForbidden();
    }
}
