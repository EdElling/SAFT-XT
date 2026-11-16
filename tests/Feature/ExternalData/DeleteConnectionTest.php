<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Filament\Resources\DatabaseConnections\Pages\EditDatabaseConnection;
use App\Filament\Resources\DatabaseConnections\Pages\ListDatabaseConnections;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\Menu;
use Database\Seeders\ExternalResearchDatabase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Datenbankverbindung löschen, auf Wunsch samt allen darüber angebundenen Tabellen. */
class DeleteConnectionTest extends TestCase
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
        $this->connection = $this->researchConnection();
    }

    private function service(): TableConnectorService
    {
        return app(TableConnectorService::class);
    }

    private function connectPeopleAndOrganisations(): void
    {
        $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, 'organisationen', ['name' => 'orgs']);
        $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'leute']);
    }

    public function test_service_removes_all_links_only_with_exact_confirmation(): void
    {
        $this->connectPeopleAndOrganisations();
        $this->assertSame(FieldType::Relation, CustomTable::query()->where('name', 'leute')->sole()->fields()->where('name', 'arbeitgeber_id')->sole()->type);

        try {
            $this->service()->deleteConnection($this->connection, 'forschung');
            $this->fail('Ohne exakte Bestätigung gelöscht.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Forschung', $e->getMessage());
        }

        $this->assertSame(2, CustomTable::query()->external()->count());
        $this->assertModelExists($this->connection);

        $this->assertSame(2, $this->service()->deleteConnection($this->connection, ' Forschung '));

        $this->assertModelMissing($this->connection);
        $this->assertSame(0, CustomTable::query()->external()->count());
        $this->assertDatabaseMissing('permissions', ['name' => 'data.leute.view']);
        $this->assertDatabaseMissing('permissions', ['name' => 'data.orgs.view']);
        $this->assertFalse(Menu::query()->whereIn('route_parameters->table', ['leute', 'orgs'])->exists());
        $this->assertSame(20, ExternalResearchDatabase::on($this->externalDatabaseName())->connection()->table('forschung.personen')->count(), 'Externe Daten bleiben unverändert.');
    }

    public function test_connection_without_tables_needs_no_confirmation(): void
    {
        $this->assertSame(0, $this->service()->deleteConnection($this->connection));
        $this->assertModelMissing($this->connection);
    }

    public function test_dialog_lists_affected_tables_and_deletes_after_confirmation(): void
    {
        $this->connectPeopleAndOrganisations();
        $this->actingAs($this->superAdmin());

        Livewire::test(EditDatabaseConnection::class, ['record' => $this->connection->getKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee([__('db_connections.delete.heading'), 'leute', 'orgs', ExternalResearchDatabase::SCHEMA.'.personen'])
            ->assertMountedActionModalDontSee(__('db_connections.delete.not_allowed'))
            ->setActionData(['confirmation' => ''])
            ->callMountedAction()
            ->assertHasActionErrors(['confirmation' => 'required']);

        $this->assertModelExists($this->connection);

        Livewire::test(EditDatabaseConnection::class, ['record' => $this->connection->getKey()])
            ->callAction('delete', ['confirmation' => 'Forschung'])
            ->assertHasNoActionErrors()
            ->assertNotified(trans_choice('db_connections.delete.done', 2, ['name' => 'Forschung', 'count' => 2]))
            ->assertRedirect();

        $this->assertModelMissing($this->connection);
        $this->assertSame(0, CustomTable::query()->external()->count());
    }

    public function test_without_table_connector_right_the_links_are_listed_but_not_deleted(): void
    {
        $this->connectPeopleAndOrganisations();
        $this->actingAs($this->userWithPermissions(['database_connections.view', 'database_connections.update', 'database_connections.delete']));

        Livewire::test(EditDatabaseConnection::class, ['record' => $this->connection->getKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee(['leute', __('db_connections.delete.not_allowed')])
            ->callMountedAction()
            ->assertNotified(__('db_connections.delete.not_allowed'));

        $this->assertModelExists($this->connection);
        $this->assertSame(2, CustomTable::query()->external()->count());
    }

    public function test_list_deletes_an_unused_connection_with_simple_confirmation(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(ListDatabaseConnections::class)
            ->callAction(TestAction::make('delete')->table($this->connection))
            ->assertHasNoActionErrors();

        $this->assertModelMissing($this->connection);
    }

    public function test_delete_needs_the_connection_right(): void
    {
        $this->actingAs($this->userWithPermissions(['database_connections.view', 'database_connections.update', 'table_connector.delete']));

        Livewire::test(EditDatabaseConnection::class, ['record' => $this->connection->getKey()])
            ->assertActionHidden('delete');
    }
}
