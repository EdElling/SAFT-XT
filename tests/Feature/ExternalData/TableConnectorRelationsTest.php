<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\Cascade\CascadePath;
use App\DynamicData\Cascade\CascadeResolver;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\TableConnector\Pages\EditConnectedTable;
use App\Filament\Resources\TableConnector\RelationManagers\ConnectedColumnsRelationManager;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Verknüpfungen und Master-Detail zwischen angebundenen Tabellen derselben Verbindung. */
class TableConnectorRelationsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    /** Auch app_data in der Test-Transaktion (für die Designer-Tabelle im Test). */
    protected array $connectionsToTransact = [null, 'app_data'];

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

    private function connect(string $table): CustomTable
    {
        return $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);
    }

    private function field(string $table, string $name): CustomField
    {
        return CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $name)->sole();
    }

    public function test_foreign_keys_become_relations_in_both_directions(): void
    {
        $this->connect('projekte');
        $this->assertSame(FieldType::Integer, $this->field('projekte', 'projektleiter_id')->type);

        // Erst mit der Zieltabelle entsteht die Verknüpfung – auch nachträglich für projekte.
        $this->connect('personen');
        $leader = $this->field('projekte', 'projektleiter_id');
        $this->assertSame(FieldType::Relation, $leader->type);
        $this->assertSame(['personen', 'vorname', 'integer'], [$leader->optionsArray()['table'], $leader->optionsArray()['display_field'], $leader->optionsArray()['base_type']]);
        $this->assertSame(FieldType::Integer, $this->field('projekte', 'partner_id')->type);

        $this->connect('organisationen');
        $this->assertSame('name', $this->field('projekte', 'partner_id')->optionsArray()['display_field']);
        $this->assertSame('organisationen', $this->field('personen', 'arbeitgeber_id')->optionsArray()['table']);
    }

    public function test_uuid_foreign_keys_keep_their_type(): void
    {
        $this->connect('publikationen');
        $authors = $this->connect('publikation_autoren');

        $publication = $this->field('publikation_autoren', 'publikation_id');
        $this->assertSame(FieldType::Relation, $publication->type);
        $this->assertSame('titel', $publication->optionsArray()['display_field']);
        $this->assertSame('string', $publication->optionsArray()['base_type']);
        // Vorher nur Text und ausgeblendet; mit Anzeigewert in der Liste sichtbar.
        $this->assertTrue($publication->show_in_table);

        $row = DynamicRecord::forTable($authors->fresh('fields'))->newQuery()->firstOrFail();
        $this->assertIsString($row->publikation_id);
    }

    public function test_list_shows_display_values_and_details_cascade_over_uuid_keys(): void
    {
        $this->actingAs($this->superAdmin());
        $projects = $this->connect('projekte');
        $this->connect('personen');
        $publications = $this->connect('publikationen');
        $authors = $this->connect('publikation_autoren');

        $this->service()->update($projects, ['detail_field_ids' => [$this->field('publikationen', 'projekt_id')->id]]);
        $this->service()->update($publications->fresh(), ['detail_field_ids' => [$this->field('publikation_autoren', 'publikation_id')->id]]);

        // Anzeigewert statt Zahl.
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSee('Gletscherschmelze und Wasserhaushalt')
            ->assertSee('Anna');

        // Projekt P-01 → Publikation (UUID) → Autoren (ohne einzelnen Schlüssel: nur Liste).
        $publication = DynamicRecord::forTable($publications->fresh('fields'))->newQuery()->where('projekt_id', 1)->orderBy('titel')->firstOrFail();
        $path = new CascadePath(1, [['field' => $this->field('publikationen', 'projekt_id')->id, 'record' => $publication->getKey()]]);
        $resolved = app(CascadeResolver::class)->resolve($projects->fresh('fields'), CascadePath::parse($path->toString()));

        $this->assertCount(2, $resolved['levels']);
        $this->assertSame($publication->getKey(), $resolved['levels'][1]['record']->getKey());
        $this->assertStringContainsString(',', (string) $path->toString());
        $this->assertStringContainsString(':s.', (string) $path->toString());

        Livewire::withQueryParams(['sel' => $path->toString()])->test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSet('selection', $path->toString())
            ->assertSee($publication->titel)
            ->assertSee(__('data.detail_label', ['table' => 'Publikation autoren', 'field' => 'Publikation']));

        // Ein Publikation aus einem anderen Projekt wird abgeschnitten.
        $foreign = DynamicRecord::forTable($publications->fresh('fields'))->newQuery()->where('projekt_id', 2)->firstOrFail();
        $wrong = new CascadePath(1, [['field' => $this->field('publikationen', 'projekt_id')->id, 'record' => $foreign->getKey()]]);
        $this->assertCount(1, app(CascadeResolver::class)->resolve($projects->fresh('fields'), $wrong)['levels']);
        $this->assertNotNull($authors);
    }

    public function test_related_values_in_tables_without_single_key_open_the_preview(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('publikationen');
        $this->connect('personen');
        $authors = $this->connect('publikation_autoren');
        $row = DynamicRecord::forTable($authors->fresh('fields'))->newQuery()->orderBy('reihenfolge')->orderBy('person_id')->firstOrFail();
        $person = $this->field('publikation_autoren', 'person_id');

        // Klick auf den verknüpften Wert: Filament sucht die Zeile über ihre Kennung wieder.
        Livewire::test(DynamicDataPage::class, ['table' => 'publikation_autoren'])
            ->mountTableAction('preview_person_id', $row)
            ->assertMountedActionModalSee('Personen:');

        // Ebenso in der Detailtabelle unter einer Publikation.
        $publications = CustomTable::query()->where('name', 'publikationen')->sole();
        $this->service()->update($publications, ['detail_field_ids' => [$this->field('publikation_autoren', 'publikation_id')->id]]);

        Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'publikationen',
            'parentId' => $row->publikation_id,
            'relationFieldId' => $this->field('publikation_autoren', 'publikation_id')->id,
            'level' => 1,
        ])
            ->assertCanSeeTableRecords([$row])
            ->mountTableAction('preview_person_id', $row)
            ->assertMountedActionModalSee('Personen:');

        $this->assertNotNull($person);
    }

    public function test_manual_changes_are_validated_and_respected(): void
    {
        $this->connect('projekte');
        $this->connect('personen');
        $leader = $this->field('projekte', 'projektleiter_id');

        // Anzeigefeld wählbar.
        $this->service()->setRelation($leader, 'personen', 'nachname');
        $this->assertSame('nachname', $leader->fresh()->optionsArray()['display_field']);

        // Von Hand entfernt: bleibt entfernt, auch wenn Fremdschlüssel erneut übernommen werden.
        $this->service()->setRelation($leader->fresh(), null, null);
        $this->assertSame(FieldType::Integer, $leader->fresh()->type);
        $this->assertSame(0, $this->service()->applyForeignKeys($this->connection));
        $this->assertSame(FieldType::Integer, $leader->fresh()->type);

        $designer = app(DynamicSchemaService::class)->createTable(['name' => 'intern', 'label' => 'Intern'], [['name' => 'titel', 'type' => 'string']]);
        $other = $this->researchConnection(['name' => 'Zweite']);
        $this->service()->connect($other, ExternalResearchDatabase::SCHEMA, 'organisationen', ['name' => 'orgs_zweite']);

        foreach ([
            fn () => $this->service()->setRelation($leader->fresh(), 'intern', 'titel'),
            fn () => $this->service()->setRelation($leader->fresh(), 'orgs_zweite', 'name'),
            fn () => $this->service()->setRelation($leader->fresh(), 'personen', 'gibt_es_nicht'),
            fn () => $this->service()->setRelation($this->field('projekte', 'beginn'), 'personen', 'nachname'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Ungültige Verknüpfung akzeptiert.');
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull($designer);
    }

    public function test_disconnecting_the_target_removes_relations_to_it(): void
    {
        $this->connect('projekte');
        $people = $this->connect('personen');
        $this->assertSame(FieldType::Relation, $this->field('projekte', 'projektleiter_id')->type);

        $this->service()->disconnect($people);

        $leader = $this->field('projekte', 'projektleiter_id');
        $this->assertSame(FieldType::Integer, $leader->type);
        $this->assertArrayNotHasKey('table', $leader->optionsArray());
        // Wird personen erneut angebunden, entsteht die Verknüpfung wieder.
        $this->connect('personen');
        $this->assertSame(FieldType::Relation, $this->field('projekte', 'projektleiter_id')->type);
    }

    public function test_column_form_shows_and_saves_the_relation(): void
    {
        $this->actingAs($this->superAdmin());
        $projects = $this->connect('projekte');
        $this->connect('personen');
        $leader = $this->field('projekte', 'projektleiter_id');

        Livewire::test(ConnectedColumnsRelationManager::class, ['ownerRecord' => $projects, 'pageClass' => EditConnectedTable::class])
            ->mountTableAction('edit', $leader)
            ->assertTableActionDataSet(['relation_table' => 'personen', 'relation_display' => ['vorname']])
            ->setTableActionData(['relation_display' => ['nachname', 'vorname'], 'relation_separator' => ', '])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $options = $leader->fresh()->optionsArray();
        $this->assertSame(['nachname', 'vorname'], $options['display_fields']);
        $this->assertSame(', ', $options['separator']);

        // Liste und Vorschau zeigen „Keller, Anna“.
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])->assertSee('Keller, Anna');

        Livewire::test(ConnectedColumnsRelationManager::class, ['ownerRecord' => $projects, 'pageClass' => EditConnectedTable::class])
            ->callTableAction('edit', $leader->fresh(), ['relation_table' => null])
            ->assertHasNoTableActionErrors();

        $this->assertSame(FieldType::Integer, $leader->fresh()->type);
    }
}
