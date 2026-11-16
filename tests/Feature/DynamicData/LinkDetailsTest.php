<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Cascade\CascadePath;
use App\DynamicData\Cascade\CascadeResolver;
use App\DynamicData\DataMenu;
use App\DynamicData\DetailRelations;
use App\DynamicData\Diagram\DataModelGraphBuilder;
use App\DynamicData\Diagram\MermaidErRenderer;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\ReportLink;
use App\Services\DynamicSchemaService;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Tabellenverknüpfungen als Details der Master-Detail-Ansicht – live, auch über Quellen hinweg. */
class LinkDetailsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    protected array $connectionsToTransact = [null, 'app_data'];

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $this->connection = $this->researchConnection();
        $this->actingAs($this->superAdmin());

        app(TableConnectorService::class)->connect($this->connection, ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'ext_personen']);

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'kontakte', 'label' => 'Kontakte'], [
            ['name' => 'betreff', 'label' => 'Betreff', 'type' => 'string', 'show_in_table' => true],
            ['name' => 'person_nr', 'label' => 'Person-Nr.', 'type' => 'integer', 'show_in_table' => true],
        ]);
        $schema->createTable(['name' => 'firmen', 'label' => 'Firmen'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'show_in_table' => true],
            ['name' => 'org_nr', 'label' => 'Org-Nr.', 'type' => 'integer', 'is_unique' => true],
        ]);

        foreach ([['A', 1], ['B', 2], ['C', 2]] as [$subject, $person]) {
            DynamicRecord::forTableName('kontakte')->create(['betreff' => $subject, 'person_nr' => $person]);
        }
    }

    public function test_external_person_shows_its_saft_contacts_and_new_ones_are_prefilled(): void
    {
        $link = $this->link('kontakte', 'person_nr', 'ext_personen', 'id', 'Person (Forschung)');
        $people = $this->table('ext_personen');

        $this->assertSame([-$link->id => 'Kontakte (über Tabellenverknüpfung „Person (Forschung)“)'], DetailRelations::options('ext_personen'));
        app(TableConnectorService::class)->update($people, ['detail_field_ids' => [-$link->id]]);
        $this->assertSame([-$link->id], $people->fresh()->detail_field_ids);

        $details = $this->detailTable('ext_personen', 2, -$link->id)
            ->assertSee('Kontakte (über Tabellenverknüpfung „Person (Forschung)“)')
            ->assertCanSeeTableRecords($this->contacts('B', 'C'))
            ->assertCanNotSeeTableRecords($this->contacts('A'));

        // Neuer Datensatz in der Detailtabelle: Quellfeld mit dem Wert des Elterndatensatzes vorbelegt.
        $details->callTableAction('create', data: ['betreff' => 'Neu'])->assertHasNoTableActionErrors();
        $this->assertSame(2, (int) DynamicRecord::forTableName('kontakte')->newQuery()->where('betreff', 'Neu')->value('person_nr'));

        // Datenansicht: Person wählen, Detailtabelle erscheint darunter.
        Livewire::test(DynamicDataPage::class, ['table' => 'ext_personen'])
            ->call('selectRecord', 2)
            ->assertSeeLivewire(DynamicDetailTable::class);
    }

    public function test_saft_company_shows_external_people_and_the_path_is_checked(): void
    {
        // Umgekehrt: angebundene Tabelle zeigt per Tabellenverknüpfung auf eine SAFT-Tabelle.
        $link = $this->link('ext_personen', 'arbeitgeber_id', 'firmen', 'org_nr', 'Arbeitgeber (SAFT)');
        $firma = DynamicRecord::forTableName('firmen')->create(['name' => 'Org eins', 'org_nr' => 1]);
        app(DynamicSchemaService::class)->updateTable($this->table('firmen'), ['detail_field_ids' => [-$link->id]]);

        $source = ExternalResearchDatabase::SCHEMA;
        $fixture = ExternalResearchDatabase::on($this->externalDatabaseName())->connection();
        $employed = $fixture->table($source.'.personen')->where('arbeitgeber_id', 1)->orderBy('id')->pluck('id')->all();
        $other = (int) $fixture->table($source.'.personen')->where('arbeitgeber_id', '<>', 1)->value('id');
        $this->assertNotEmpty($employed);

        $this->detailTable('firmen', $firma->id, -$link->id)
            ->assertCanSeeTableRecords(DynamicRecord::forTable($this->table('ext_personen'))->newQuery()->whereKey($employed)->get())
            ->assertCanNotSeeTableRecords(DynamicRecord::forTable($this->table('ext_personen'))->newQuery()->whereKey([$other])->get());

        // Auswahlpfad mit negativer Nummer: passender Datensatz bleibt, unpassender fällt weg.
        $resolver = app(CascadeResolver::class);
        $ok = $resolver->resolve($this->table('firmen'), CascadePath::parse($firma->id.',-'.$link->id.':'.$employed[0]));
        $this->assertCount(2, $ok['levels']);
        $wrong = $resolver->resolve($this->table('firmen'), CascadePath::parse($firma->id.',-'.$link->id.':'.$other));
        $this->assertCount(1, $wrong['levels']);
        $this->assertSame($firma->id.',-'.$link->id, $wrong['path']->toString());

        // Verknüpfung gelöscht: Detail verschwindet aus der Konfiguration.
        $link->delete();
        $this->assertCount(0, DetailRelations::configuredFor($this->table('firmen')->fresh()));
    }

    public function test_details_are_offered_in_the_designer_and_drawn_in_the_data_model(): void
    {
        $link = $this->link('ext_personen', 'arbeitgeber_id', 'firmen', 'org_nr', 'Arbeitgeber (SAFT)');
        $firmen = $this->table('firmen');

        // Table-Designer: die Tabellenverknüpfung steht unter „Details“ zur Wahl und wird gespeichert.
        Livewire::test(EditCustomTable::class, ['record' => $firmen->getRouteKey()])
            ->assertSee('Personen (über Tabellenverknüpfung „Arbeitgeber (SAFT)“)', false)
            ->fillForm(['detail_field_ids' => [(string) -$link->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame([-$link->id], $firmen->fresh()->detail_field_ids);

        // Datenmodell: Kante mit „⇄“ und durchgezogen (als Detail eingerichtet).
        $menu = app(DataMenu::class);
        $group = Menu::create(['label' => ['de' => 'Gruppe'], 'sort_order' => 900, 'is_active' => true]);
        $menu->create($firmen, $group->id);
        $menu->create($this->table('ext_personen'), $group->id);
        $graph = app(DataModelGraphBuilder::class)->build($menu->ensureDataModel($group), auth()->user());
        $diagram = app(MermaidErRenderer::class)->render($graph, withFields: false);

        $this->assertStringContainsString('⇄ Arbeitgeber (SAFT)', $diagram);
        $this->assertMatchesRegularExpression('/ext_personen\S* }o--o\| \S*firmen\S* : "⇄ Arbeitgeber \(SAFT\)"/', $diagram);
    }

    private function link(string $source, string $sourceField, string $target, string $targetField, string $label): ReportLink
    {
        $targetTable = $this->table($target);

        return ReportLink::create([
            'label' => ['de' => $label],
            'source_table_id' => $this->table($source)->id,
            'source_field_id' => $this->fieldId($source, $sourceField),
            'target_table_id' => $targetTable->id,
            'target_field_id' => $targetField === 'id' && ! $targetTable->isExternal() ? null : $this->fieldId($target, $targetField),
        ]);
    }

    private function detailTable(string $parent, int $parentId, int $detailId)
    {
        return Livewire::test(DynamicDetailTable::class, ['parentTable' => $parent, 'parentId' => $parentId, 'relationFieldId' => $detailId, 'level' => 1, 'selectedId' => null]);
    }

    private function contacts(string ...$subjects)
    {
        return DynamicRecord::forTableName('kontakte')->newQuery()->whereIn('betreff', $subjects)->get();
    }

    private function table(string $name): CustomTable
    {
        return CustomTable::query()->where('name', $name)->sole();
    }

    private function fieldId(string $table, string $field): int
    {
        return (int) $this->table($table)->fields()->where('name', $field)->value('id');
    }
}
