<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\DynamicData\DataPermissions;
use App\ExternalData\Snapshots\SnapshotService;
use App\ExternalData\TableConnectorService;
use App\Filament\Resources\ReportLinks\Pages\CreateReportLink;
use App\Filament\Resources\ReportLinks\Pages\EditReportLink;
use App\Filament\Resources\ReportLinks\ReportLinkResource;
use App\Filament\Resources\Reports\Pages\ReportDesigner;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Models\ReportLink;
use App\Models\User;
use App\Reports\Editor\FieldCatalog;
use App\Reports\InvalidReportException;
use App\Reports\Links\ReportLinks;
use App\Reports\ReportDefinition;
use App\Reports\ReportRunner;
use App\Services\DynamicSchemaService;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Tabellenverknüpfungen: Regeln, Verwaltung, Treffer (Etappe 3b). */
class ReportLinksTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    protected array $connectionsToTransact = [null, 'app_data'];

    private User $admin;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $this->connection = $this->researchConnection();
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        foreach (['organisationen', 'personen'] as $table) {
            app(TableConnectorService::class)->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => 'ext_'.$table]);
        }

        // SAFT-Tabelle mit Nummern, die auf die externe Datenbank verweisen (ohne echte Verknüpfung).
        app(DynamicSchemaService::class)->createTable(['name' => 'kontakte', 'label' => 'Kontakte'], [
            ['name' => 'betreff', 'label' => 'Betreff', 'type' => 'string', 'is_unique' => true],
            ['name' => 'person_nr', 'label' => 'Person-Nr.', 'type' => 'integer'],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'string'],
        ]);

        foreach ([['A', 1], ['B', 2], ['C', 2], ['D', 9999], ['E', null]] as [$subject, $person]) {
            DynamicRecord::forTableName('kontakte')->create(['betreff' => $subject, 'person_nr' => $person]);
        }
    }

    public function test_rules_for_source_and_target_fields(): void
    {
        $links = app(ReportLinks::class);
        [$contacts, $people] = [$this->table('kontakte'), $this->table('ext_personen')];

        $this->assertSame(['betreff', 'person_nr', 'notiz'], $this->names($links->sourceOptions($contacts), $contacts));
        // SAFT-Tabelle: id und eindeutige Felder; angebunden: nur der Schlüssel.
        $this->assertSame([ReportLinks::ID, 'betreff'], $this->names($links->targetOptions($contacts), $contacts));
        $this->assertSame([ReportLinks::ID], $this->names($links->targetOptions($contacts, ReportLinks::KIND_INTEGER), $contacts));
        $this->assertSame(['id'], $this->names($links->targetOptions($people), $people));

        $field = fn (string $table, string $name): int => (int) $this->table($table)->fields()->where('name', $name)->value('id');
        $sourceField = fn (string $name) => $contacts->fields()->where('name', $name)->sole();

        $this->assertNull($links->problem($contacts, $sourceField('person_nr'), $people, $field('ext_personen', 'id')));
        $this->assertStringContainsString('passen nicht zusammen', (string) $links->problem($contacts, $sourceField('notiz'), $people, $field('ext_personen', 'id')));
        $this->assertStringContainsString('nicht eindeutig', (string) $links->problem($contacts, $sourceField('person_nr'), $people, $field('ext_personen', 'nachname')));
        $this->assertStringContainsString('nicht eindeutig', (string) $links->problem($people, $people->fields()->where('name', 'arbeitgeber_id')->sole(), $contacts, $field('kontakte', 'notiz')));
        $this->assertStringContainsString('dasselbe Feld', (string) $links->problem($contacts, $sourceField('betreff'), $contacts, $field('kontakte', 'betreff')));
    }

    public function test_create_with_coverage_and_edit(): void
    {
        $people = $this->table('ext_personen');
        $data = [
            'label' => ['de' => 'Person (Forschung)'],
            'source_table_id' => $this->table('kontakte')->id,
            'source_field_id' => $this->table('kontakte')->fields()->where('name', 'person_nr')->value('id'),
            'target_table_id' => $people->id,
            'target_field' => (string) $people->fields()->where('name', 'id')->value('id'),
        ];

        // Ohne Datenstand keine Aussage, aber speicherbar.
        Livewire::test(CreateReportLink::class)
            ->fillForm($data)
            ->assertSee('Keine Aussage')
            ->assertSeeHtml('data-link-coverage');

        app(SnapshotService::class)->refresh($people, $this->admin);

        Livewire::test(CreateReportLink::class)
            ->fillForm($data)
            // 4 Kontakte mit Nummer, 3 davon (1, 2, 2) gibt es als Person.
            ->assertSee('3 von 4 Zeilen mit Wert finden ein Ziel (75 %).')
            ->call('create')
            ->assertHasNoFormErrors();

        $link = ReportLink::query()->sole();
        $this->assertSame('id', $link->targetColumn());
        $this->assertNotNull($link->target_field_id);

        // Zweite Verknüpfung desselben Felds zur selben Tabelle: abgelehnt.
        Livewire::test(CreateReportLink::class)->fillForm($data)->call('create')->assertHasFormErrors(['source_field_id']);

        // Bearbeiten: Ziel id einer SAFT-Tabelle wird als leeres target_field_id gespeichert.
        Livewire::test(EditReportLink::class, ['record' => $link->getRouteKey()])
            ->assertFormSet(['target_field' => (string) $link->target_field_id])
            ->fillForm(['target_table_id' => $this->table('kontakte')->id])
            ->fillForm(['target_field' => ReportLinks::ID])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($link->fresh()->target_field_id);
        $this->assertSame('id', $link->fresh()->targetColumn());
    }

    public function test_rights_and_cascade(): void
    {
        $this->get(ReportLinkResource::getUrl('index'))->assertOk()->assertSee('Tabellenverknüpfungen');

        $this->actingAs($this->userWithPermissions(['reports.view']));
        $this->get(ReportLinkResource::getUrl('index'))->assertForbidden();

        $this->actingAs($this->userWithPermissions(['report_links.view']));
        $this->get(ReportLinkResource::getUrl('index'))->assertOk();
        $this->get(ReportLinkResource::getUrl('create'))->assertForbidden();

        $contacts = $this->table('kontakte');
        ReportLink::create([
            'label' => ['de' => 'Person'],
            'source_table_id' => $contacts->id,
            'source_field_id' => $contacts->fields()->where('name', 'person_nr')->value('id'),
            'target_table_id' => $this->table('ext_personen')->id,
            'target_field_id' => $this->table('ext_personen')->fields()->where('name', 'id')->value('id'),
        ]);

        // Fällt die Tabelle weg, verschwindet die Verknüpfung mit.
        app(TableConnectorService::class)->disconnect($this->table('ext_personen'));
        $this->assertSame(0, ReportLink::query()->count());
    }

    public function test_report_joins_saft_table_with_snapshot_and_continues_over_relations(): void
    {
        $link = $this->link('kontakte', 'person_nr', 'ext_personen', 'id', 'Person (Forschung)');
        $this->snapshot('ext_personen', 'ext_organisationen');

        $source = ExternalResearchDatabase::SCHEMA;
        $fixture = $this->researchFixture();
        $name = fn (int $id): string => (string) $fixture->table($source.'.personen')->where('id', $id)->value('nachname');
        $land = fn (int $id): string => (string) $fixture->table($source.'.personen as p')->join($source.'.organisationen as o', 'o.id', '=', 'p.arbeitgeber_id')->where('p.id', $id)->value('o.land');

        $result = app(ReportRunner::class)->run(ReportDefinition::fromArray([
            'base' => $this->table('kontakte')->id,
            'fields' => [
                $this->ref('betreff', 'kontakte.betreff'),
                $this->ref('name', 'ext_personen.nachname', [-$link->id]),
                // Weiter über das Verknüpfungsfeld der angebundenen Tabelle.
                $this->ref('land', 'ext_organisationen.land', [-$link->id, 'ext_personen.arbeitgeber_id']),
            ],
            'sort' => [['key' => 'betreff', 'dir' => 'asc']],
        ]), $this->admin);

        $this->assertSame([
            ['betreff' => 'A', 'name' => $name(1), 'land' => $land(1)],
            ['betreff' => 'B', 'name' => $name(2), 'land' => $land(2)],
            ['betreff' => 'C', 'name' => $name(2), 'land' => $land(2)],
            ['betreff' => 'D', 'name' => null, 'land' => null],
            ['betreff' => 'E', 'name' => null, 'land' => null],
        ], $result->rows, 'Keine Treffer bleiben leer (LEFT JOIN), keine Zeile vervielfacht.');
        $this->assertSame('Person (Forschung) › Nachname', $result->report->field('name')->label);
        $this->assertArrayHasKey($this->table('ext_personen')->id, $result->report->snapshots);
    }

    public function test_link_between_snapshots_of_two_connections(): void
    {
        $second = $this->researchConnection(['name' => 'Forschung 2']);
        app(TableConnectorService::class)->connect($second, ExternalResearchDatabase::SCHEMA, 'projekte', ['name' => 'ext2_projekte']);
        $link = $this->link('ext2_projekte', 'projektleiter_id', 'ext_personen', 'id', 'Leitung');
        $this->snapshot('ext2_projekte', 'ext_personen');

        $source = ExternalResearchDatabase::SCHEMA;
        $expected = $this->researchFixture()->table($source.'.projekte as pr')->join($source.'.personen as p', 'p.id', '=', 'pr.projektleiter_id')
            ->selectRaw('p.nachname as name, count(*) as n')->groupBy('p.nachname')->get()
            ->mapWithKeys(fn (object $r): array => [$r->name => (int) $r->n])->sortKeys()->all();

        $result = app(ReportRunner::class)->run(ReportDefinition::fromArray([
            'base' => $this->table('ext2_projekte')->id,
            'fields' => [$this->ref('name', 'ext_personen.nachname', [-$link->id])],
            'groups' => ['name'],
            'measures' => [['key' => 'n', 'agg' => 'count']],
        ]), $this->admin);

        $this->assertSame($expected, collect($result->rows)->mapWithKeys(fn (array $r): array => [$r['name'] => $r['n']])->sortKeys()->all());
    }

    public function test_deleted_link_rights_and_editor(): void
    {
        $link = $this->link('kontakte', 'person_nr', 'ext_personen', 'id', 'Person (Forschung)');
        $definition = ReportDefinition::fromArray([
            'base' => $this->table('kontakte')->id,
            'fields' => [$this->ref('name', 'ext_personen.nachname', [-$link->id])],
        ]);

        // Editor: Ziel ist erreichbar, beim Wählen entsteht sein Datenstand, das Feld erscheint im Baum.
        $catalog = app(FieldCatalog::class);
        $this->assertContains($this->table('ext_personen')->id, $catalog->reachableFrom($this->table('kontakte')->id, $this->admin));
        $this->assertContains($this->table('ext_personen')->id, array_map(fn (CustomTable $t): int => $t->id, $catalog->externalTablesFor($this->table('kontakte'), $this->admin)));
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $this->table('kontakte')->id)
            ->assertSet('state.base', $this->table('kontakte')->id)
            ->assertSee('Person (Forschung)')
            ->assertSeeHtml('data-ref="'.FieldCatalog::ref([-$link->id], $this->fieldId('ext_personen', 'nachname')).'"');
        $this->assertNotNull($this->table('ext_personen')->latestSnapshot());

        // Ohne Leserecht auf die Zieltabelle nicht ausführbar.
        $reader = $this->userWithPermissions(['reports.view', DataPermissions::name('kontakte', 'view')]);
        $this->assertFalse(app(ReportRunner::class)->canRun($definition, $reader));
        $this->assertTrue(app(ReportRunner::class)->canRun($definition, $this->admin));

        $link->delete();

        try {
            app(ReportRunner::class)->run($definition, $this->admin);
            $this->fail('Fehler erwartet');
        } catch (InvalidReportException $e) {
            $this->assertStringContainsString('Tabellenverknüpfung „#'.$link->id.'“ ab „Kontakte“ gibt es nicht mehr', $e->errors[0]);
        }
    }

    public function test_migration_renames_only_the_default_menu_entry(): void
    {
        $route = 'filament.admin.resources.report-links.index';
        $default = DB::table('menus')->insertGetId(['label' => json_encode(['de' => 'Verknüpfungen für Auswertungen', 'en' => 'Report links', 'fr' => 'Liaisons pour rapports', 'it' => 'Collegamenti per report'], JSON_UNESCAPED_UNICODE), 'route' => $route, 'sort_order' => 73, 'is_active' => true]);
        $custom = DB::table('menus')->insertGetId(['label' => json_encode(['de' => 'Meine Links', 'en' => 'My links']), 'route' => $route, 'sort_order' => 74, 'is_active' => true]);

        (require database_path('migrations/2026_12_07_100000_rename_report_links_menu_entry.php'))->up();

        $label = fn (int $id): array => json_decode((string) DB::table('menus')->where('id', $id)->value('label'), true);
        $this->assertSame(['de' => 'Tabellenverknüpfungen', 'en' => 'Table links', 'fr' => 'Liaisons de tables', 'it' => 'Collegamenti tra tabelle'], $label($default));
        $this->assertSame(['de' => 'Meine Links', 'en' => 'My links'], $label($custom));
    }

    private function link(string $source, string $sourceField, string $target, string $targetField, string $label): ReportLink
    {
        return ReportLink::create([
            'label' => ['de' => $label],
            'source_table_id' => $this->table($source)->id,
            'source_field_id' => $this->fieldId($source, $sourceField),
            'target_table_id' => $this->table($target)->id,
            'target_field_id' => $targetField === 'id' && ! $this->table($target)->isExternal() ? null : $this->fieldId($target, $targetField),
        ]);
    }

    private function snapshot(string ...$tables): void
    {
        foreach ($tables as $table) {
            app(SnapshotService::class)->refresh($this->table($table), $this->admin);
        }
    }

    private function researchFixture(): Connection
    {
        return ExternalResearchDatabase::on($this->externalDatabaseName())->connection();
    }

    private function fieldId(string $table, string $field): int
    {
        return (int) $this->table($table)->fields()->where('name', $field)->value('id');
    }

    /**
     * @param  list<int|string>  $path  Verknüpfungsfelder als tabelle.feld, Verknüpfungen als negative Nummer
     * @return array<string, mixed>
     */
    private function ref(string $key, string $field, array $path = []): array
    {
        [$table, $name] = explode('.', $field);
        $step = fn (int|string $p): int => is_int($p) ? $p : $this->fieldId(...explode('.', $p));

        return ['key' => $key, 'path' => array_map($step, $path), 'field' => $this->fieldId($table, $name), 'bucket' => null, 'label' => null];
    }

    private function table(string $name): CustomTable
    {
        return CustomTable::query()->where('name', $name)->sole();
    }

    /**
     * @param  array<int|string, string>  $options
     * @return list<string>
     */
    private function names(array $options, CustomTable $table): array
    {
        return array_map(fn (int|string $key): string => $key === ReportLinks::ID ? ReportLinks::ID : (string) $table->fields()->whereKey($key)->value('name'), array_keys($options));
    }
}
