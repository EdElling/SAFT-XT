<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\ExternalData\TableConnectorService;
use App\Filament\Resources\TableConnector\Pages\ListConnectedTables;
use App\Models\CustomTable;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Filter und Sortierung der Liste im Table-Connector. */
class ConnectedTablesListTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    public function test_list_filters_by_connection_kind_and_settings(): void
    {
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $this->actingAs($this->superAdmin());
        $service = app(TableConnectorService::class);
        $first = $this->researchConnection(['is_read_only' => false]);
        $second = $this->researchConnection(['name' => 'Zweite']);

        $people = $service->connect($first, ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'personen']);
        $service->update($people, ['allow_update' => true, 'export_enabled' => true]);
        $view = $service->connect($first, ExternalResearchDatabase::SCHEMA, 'projekt_uebersicht', ['name' => 'uebersicht']);
        $orgs = $service->connect($second, ExternalResearchDatabase::SCHEMA, 'organisationen', ['name' => 'orgs']);
        $service->update($orgs, ['is_active' => false]);

        $list = fn () => Livewire::test(ListConnectedTables::class);

        $list()->filterTable('database_connection_id', [$second->id])
            ->assertCanSeeTableRecords([$orgs])->assertCanNotSeeTableRecords([$people, $view]);
        $this->flushSession();
        $list()->filterTable('external_kind', 'view')
            ->assertCanSeeTableRecords([$view])->assertCanNotSeeTableRecords([$people, $orgs]);
        $this->flushSession();
        $list()->filterTable('writable', true)
            ->assertCanSeeTableRecords([$people])->assertCanNotSeeTableRecords([$view, $orgs]);
        $this->flushSession();
        $list()->filterTable('writable', false)
            ->assertCanSeeTableRecords([$view, $orgs])->assertCanNotSeeTableRecords([$people]);
        $this->flushSession();
        $list()->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$orgs])->assertCanNotSeeTableRecords([$people]);
        $this->flushSession();
        $list()->filterTable('export_enabled', true)
            ->assertCanSeeTableRecords([$people])->assertCanNotSeeTableRecords([$orgs]);
        $this->flushSession();
        $list()->filterTable('external_schema', [ExternalResearchDatabase::SCHEMA])
            ->assertCanSeeTableRecords(CustomTable::query()->external()->get());
        $this->flushSession();
        $list()->sortTable('external_kind', 'desc')->assertCanSeeTableRecords([$view, $people], inOrder: true);
    }
}
