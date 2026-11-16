<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\Diagram\DataModelGraphBuilder;
use App\DynamicData\Diagram\MermaidErRenderer;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DataModelPage;
use App\Models\Menu;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Datenmodell mit angebundenen Tabellen: Kennzeichnung, Verbindung, Schlüssel aus der Quelle. */
class DataModelExternalTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    public function test_linked_tables_are_marked_with_their_connection_and_keys(): void
    {
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $connection = $this->researchConnection();
        $group = Menu::create(['label' => 'Forschung', 'sort_order' => 50]);
        $service = app(TableConnectorService::class);
        $service->connect($connection, ExternalResearchDatabase::SCHEMA, 'organisationen', ['name' => 'orgs', 'menu_group' => (string) $group->id]);
        $service->connect($connection, ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'leute', 'menu_group' => (string) $group->id]);
        $service->connect($connection, ExternalResearchDatabase::SCHEMA, 'publikation_autoren', ['name' => 'autoren', 'menu_group' => (string) $group->id]);
        $entry = Menu::create(['label' => 'Modell', 'parent_id' => $group->id, 'route' => DataModelPage::ROUTE]);

        $graph = app(DataModelGraphBuilder::class)->build($entry->fresh(), $this->superAdmin());
        $renderer = app(MermaidErRenderer::class);
        $compact = $renderer->render($graph, withFields: false);
        $expanded = $renderer->render($graph, withFields: true);

        $this->assertEqualsCanonicalizing(['orgs', 'leute', 'autoren'], array_keys($graph->tables));
        $this->assertStringContainsString('(Forschung)"]', $compact);
        $this->assertStringContainsString('T_leute }o..', $compact);
        $this->assertStringContainsString('classDef external', $compact);
        $this->assertMatchesRegularExpression('/class (T_\w+,){2}T_\w+ external/', $compact);
        $this->assertStringNotContainsString('integer id PK "ID"', $expanded, 'Externe Tabellen zeigen ihre eigenen Schlüssel.');
        $this->assertMatchesRegularExpression('/ publikation_id PK "/', $expanded, 'Publikationen nicht angebunden: kein Verknüpfungsfeld.');
        $this->assertMatchesRegularExpression('/ person_id PK, FK /', $expanded);
    }
}
