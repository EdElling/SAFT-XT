<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\ColumnInfo;
use App\ExternalData\SchemaInspector;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\TableConnector\Pages\CreateConnectedTable;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Role;
use App\Services\DynamicSchemaService;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Table-Connector, Etappe 2: Tabellen und Views externer Datenbanken lesend anbinden. */
class TableConnectorTest extends TestCase
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

    private function connect(string $table, string $schema = ExternalResearchDatabase::SCHEMA, ?string $name = null): CustomTable
    {
        return $this->service()->connect($this->connection, $schema, $table, ['name' => $name ?? $table]);
    }

    /** @return array<string, ColumnInfo> */
    private function columns(string $table, string $schema = ExternalResearchDatabase::SCHEMA): array
    {
        $columns = [];

        foreach (app(SchemaInspector::class)->columns($this->connection, $schema, $table) as $column) {
            $columns[$column->name] = $column;
        }

        return $columns;
    }

    public function test_inspector_lists_tables_views_and_keys(): void
    {
        $tables = collect(app(SchemaInspector::class)->tables($this->connection, ExternalResearchDatabase::SCHEMA))->keyBy('name');

        $this->assertSame(['laender', 'organisationen', 'personen', 'projekt_uebersicht', 'projekte', 'publikation_autoren', 'publikationen'], $tables->keys()->all());
        $this->assertTrue($tables['projekt_uebersicht']->isView());
        $this->assertSame(['id'], $tables['projekte']->keyColumns);
        $this->assertSame(['publikation_id', 'person_id'], $tables['publikation_autoren']->keyColumns);
        $this->assertSame('Forschungsprojekte mit Leitung und Partner', $tables['projekte']->comment);

        $public = app(SchemaInspector::class)->tables($this->connection, 'public');
        $this->assertSame([], $public[0]->keyColumns);
    }

    public function test_inspector_maps_types_and_detects_generated_columns(): void
    {
        $organisations = $this->columns('organisationen');
        $people = $this->columns('personen');
        $projects = $this->columns('projekte');
        $publications = $this->columns('publikationen');

        // Identity (always / by default), Sequenz, berechnete Spalte.
        $this->assertTrue($organisations['id']->generated);
        $this->assertTrue($projects['id']->generated);
        $this->assertTrue($people['id']->generated);
        $this->assertTrue($people['vollname']->generated);
        $this->assertFalse($people['vorname']->generated);

        $this->assertSame(FieldType::String, $organisations['name']->type);
        $this->assertSame(['length' => 200], $organisations['name']->options);
        $this->assertTrue($organisations['name']->unique);
        $this->assertTrue($organisations['name']->isRequired());
        $this->assertSame(FieldType::Decimal, $projects['budget']->type);
        $this->assertSame(['precision' => 12, 'scale' => 2], $projects['budget']->options);
        $this->assertSame(FieldType::Date, $projects['beginn']->type);
        $this->assertSame(FieldType::DateTime, $people['erstellt_am']->type);
        $this->assertSame(FieldType::Boolean, $publications['open_access']->type);

        // Nur als Text: uuid und jsonb; nicht unterstützt: bytea.
        $this->assertTrue($publications['id']->textOnly);
        $this->assertTrue($projects['metadaten']->textOnly);
        $this->assertSame(FieldType::Text, $projects['metadaten']->type);
        $this->assertFalse($people['foto']->isSupported());
        $this->assertSame(ColumnInfo::UNSUPPORTED_TYPE, $people['foto']->unsupported);
    }

    public function test_connect_creates_metadata_permissions_and_menu_entry(): void
    {
        $projects = $this->connect('projekte', name: 'forschung_projekte');

        $this->assertTrue($projects->isExternal());
        $this->assertSame(['id'], $projects->key_columns);
        $this->assertSame('table', $projects->external_kind);
        $this->assertSame('Projekte', $projects->getTranslation('label', 'de'));
        $this->assertSame('Forschungsprojekte mit Leitung und Partner', $projects->getTranslation('description', 'de'));
        $this->assertFalse($projects->export_enabled);

        $fields = $projects->fields->keyBy('name');
        $this->assertSame(['id', 'kurzname', 'titel', 'beginn', 'ende', 'budget', 'status', 'projektleiter_id', 'partner_id', 'metadaten'], $fields->keys()->all());
        $this->assertSame('Projektleiter', $fields['projektleiter_id']->getTranslation('label', 'de'));
        $this->assertSame('character varying(30)', $fields['kurzname']->external_type);
        $this->assertTrue($fields['id']->is_generated);
        $this->assertTrue($fields['id']->is_read_only);
        $this->assertTrue($fields['metadaten']->is_read_only);
        $this->assertFalse($fields['metadaten']->show_in_table);
        $this->assertTrue($fields['kurzname']->is_searchable);
        $this->assertFalse($fields['kurzname']->is_required);
        $this->assertTrue($fields['kurzname']->optionsArray()['db_required']);

        foreach (DataPermissions::names('forschung_projekte') as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission]);
        }

        $this->assertTrue(app(DataMenu::class)->exists($projects));
        $this->assertFalse($this->connect('personen')->fields->contains('name', 'foto'));
    }

    public function test_connect_validates_name_and_source(): void
    {
        $this->connect('projekte');

        foreach ([['projekte', 'projekte'], ['Ungültig!', 'personen'], ['personen2', 'gibt_es_nicht']] as [$name, $table]) {
            try {
                $this->connect($table, name: $name);
                $this->fail("Angebunden trotz {$name}/{$table}");
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->connection->update(['is_active' => false]);
        $this->expectException(BusinessRuleException::class);
        $this->connect('personen');
    }

    public function test_records_are_read_through_the_external_connection(): void
    {
        $people = DynamicRecord::forTable($this->connect('personen'));
        $this->assertSame(20, $people->newQuery()->count());
        $this->assertSame('Anna Keller', $people->newQuery()->find(1)?->vollname);
        $this->assertSame('forschung.personen', $people->getTable());

        // UUID-Schlüssel.
        $publications = DynamicRecord::forTable($this->connect('publikationen'));
        $this->assertFalse(CustomTable::query()->where('name', 'publikationen')->sole()->fields->firstWhere('name', 'id')->show_in_table);
        $first = $publications->newQuery()->orderBy('titel')->firstOrFail();
        $this->assertSame('string', $first->getKeyType());
        $this->assertSame($first->titel, $publications->newQuery()->find($first->getKey())?->titel);

        // View und Tabelle ohne Schlüssel: lesbar, aber ohne eindeutigen Schlüssel.
        $overview = DynamicRecord::forTable($this->connect('projekt_uebersicht'));
        $this->assertSame(12, $overview->newQuery()->count());
        $this->assertFalse($overview->hasUniqueKey());
        $log = DynamicRecord::forTable($this->connect('importprotokoll', 'public'));
        $this->assertSame(5, $log->newQuery()->count());

        // Ohne deleted_at: withTrashed() ist wirkungslos, onlyTrashed() leer.
        $this->assertSame(20, $people->newQuery()->withTrashed()->count());
        $this->assertSame(0, $people->newQuery()->onlyTrashed()->count());
    }

    public function test_external_records_cannot_be_written(): void
    {
        $people = DynamicRecord::forTable($this->connect('personen'));
        $anna = $people->newQuery()->findOrFail(1);

        // Ohne freigeschaltetes Schreiben lehnt das Model jedes Speichern ab.
        $this->expectException(LogicException::class);
        $anna->update(['vorname' => 'Anne']);
    }

    public function test_table_designer_never_touches_external_tables(): void
    {
        $projects = $this->connect('projekte');

        foreach ([
            fn () => app(DynamicSchemaService::class)->updateTable($projects, ['label' => 'X']),
            fn () => app(DynamicSchemaService::class)->addField($projects, ['name' => 'neu', 'label' => 'Neu', 'type' => 'string']),
            fn () => app(DynamicSchemaService::class)->dropTable($projects, 'projekte'),
            fn () => app(DynamicSchemaService::class)->createTable(['name' => 'intern', 'label' => 'Intern'], [
                ['name' => 'projekt_id', 'label' => 'Projekt', 'type' => 'relation', 'options' => ['table' => 'projekte', 'display_field' => 'titel']],
            ]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Table-Designer hat eine externe Tabelle verändert oder verknüpft.');
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actingAs($this->superAdmin());
        $this->get('/admin/table-designer')->assertOk()->assertDontSee('forschung.projekte');
        $this->get("/admin/table-designer/{$projects->id}/edit")->assertNotFound();
    }

    public function test_data_page_lists_external_records_read_only(): void
    {
        $this->actingAs($this->superAdmin());
        $people = $this->connect('personen');
        $anna = DynamicRecord::forTable($people)->newQuery()->findOrFail(1);

        Livewire::test(DynamicDataPage::class, ['table' => 'personen'])
            ->assertCanSeeTableRecords([$anna])
            ->assertTableActionHidden('create')
            ->searchTable('Keller')
            ->assertCanSeeTableRecords([$anna])
            ->assertTableActionVisible('details', $anna)
            ->mountTableAction('details', $anna)
            ->assertMountedActionModalSee('Anna')
            ->assertMountedActionModalDontSee(['Bearbeiten', 'Verlauf', 'Löschen']);

        // Auswahl mit UUID-Schlüssel landet kodiert in der Adresse und findet den Datensatz wieder.
        $publications = $this->connect('publikationen');
        $publication = DynamicRecord::forTable($publications)->newQuery()->orderBy('titel')->firstOrFail();

        Livewire::test(DynamicDataPage::class, ['table' => 'publikationen'])
            ->call('selectRecord', $publication->getKey())
            ->assertSet('selection', 's.'.rtrim(strtr(base64_encode((string) $publication->getKey()), '+/', '-_'), '='))
            ->assertSee($publication->titel);
    }

    public function test_export_works_for_external_tables_and_tables_without_key(): void
    {
        $this->actingAs($this->superAdmin());

        foreach (['personen' => [ExternalResearchDatabase::SCHEMA, 21, 'Keller'], 'importprotokoll' => ['public', 6, 'ORCID-Abgleich']] as $table => [$schema, $lines, $text]) {
            $connected = $this->connect($table, $schema);
            app(TableConnectorService::class)->update($connected, ['export_enabled' => true]);

            $download = Livewire::test(DynamicDataPage::class, ['table' => $table])
                ->call('mountAction', 'export_csv', [], ['table' => true])
                ->effects['download'] ?? null;

            $this->assertNotNull($download, $table);
            $csv = (string) base64_decode($download['content']);
            $this->assertCount($lines, array_filter(explode("\n", trim($csv))), $table);
            $this->assertStringContainsString($text, $csv);
        }
    }

    public function test_tables_without_key_are_list_only(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('projekt_uebersicht');

        Livewire::test(DynamicDataPage::class, ['table' => 'projekt_uebersicht'])
            ->assertSee('P-01')
            ->assertSee('Gletscherschmelze und Wasserhaushalt')
            ->call('selectRecord', '1')
            ->assertSet('selection', null);
    }

    public function test_unavailable_connection_shows_a_message_instead_of_an_error(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('personen');

        $this->connection->update(['port' => 1]);
        $this->get('/admin/data/personen')->assertOk()->assertSee(__('table_connector.unavailable_title'))->assertDontSee('Anna');

        $this->connection->update(['is_active' => false]);
        $this->get('/admin/data/personen')->assertOk()->assertSee(__('table_connector.unavailable_title'));
    }

    public function test_role_matrix_offers_only_view_and_export_for_external_tables(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('personen');
        $role = Role::create(['name' => 'forschung', 'guard_name' => 'web']);
        // Ein früher gesetztes Recht außerhalb der Matrix bleibt unberührt.
        $role->givePermissionTo(DataPermissions::name('personen', 'delete'));

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['data_permissions.personen' => ['view', 'export', 'create']])
            ->call('save')
            ->assertHasFormErrors(['data_permissions.personen.2']);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['data_permissions.personen' => ['view', 'export']])
            ->call('save')
            ->assertHasNoFormErrors();

        $names = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame(['data.personen.delete', 'data.personen.export', 'data.personen.view'], $names);
    }

    public function test_connection_in_use_cannot_be_deleted_and_disconnect_cleans_up(): void
    {
        $people = $this->connect('personen');

        try {
            $this->connection->delete();
            $this->fail('Verbindung trotz angebundener Tabelle gelöscht.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Table-Connector', $e->getMessage());
        }

        $this->service()->disconnect($people);

        $this->assertDatabaseMissing('custom_tables', ['name' => 'personen']);
        $this->assertDatabaseMissing('permissions', ['name' => 'data.personen.view']);
        $this->assertFalse(Menu::query()->where('route_parameters->table', 'personen')->exists());
        $this->assertSame(20, ExternalResearchDatabase::on($this->externalDatabaseName())->connection()->table('forschung.personen')->count());
        $this->assertTrue($this->connection->delete());
    }

    public function test_resource_uses_its_own_permissions(): void
    {
        $this->connect('personen');

        $this->actingAs($this->userWithPermissions(['custom_tables.view', 'custom_tables.update']))->get('/admin/table-connector')->assertForbidden();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $reader = $this->userWithPermissions(['table_connector.view']);
        $this->actingAs($reader)->get('/admin/table-connector')->assertOk()->assertSee('forschung.personen');
        $this->actingAs($reader)->get('/admin/table-connector/create')->assertForbidden();
    }

    public function test_create_page_connects_a_table(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateConnectedTable::class)
            ->fillForm(['database_connection_id' => $this->connection->id])
            ->assertFormSet(['external_schema' => 'forschung'])
            ->fillForm(['external_table' => 'organisationen'])
            ->assertFormSet(['name' => 'forschung_organisationen', 'label.de' => 'Organisationen'])
            ->assertSee('7 Spalten werden übernommen.')
            ->fillForm(['label.en' => 'Organisations'])
            ->call('create')
            ->assertHasNoFormErrors();

        // Zweite Anbindung unter demselben Namen: Meldung nennt die vorhandene Tabelle samt Verbindung.
        Livewire::test(CreateConnectedTable::class)
            ->fillForm(['database_connection_id' => $this->connection->id])
            ->fillForm(['external_table' => 'organisationen'])
            ->fillForm(['name' => 'forschung_organisationen'])
            ->assertHasFormErrors(['name'])
            ->assertSee('ist bereits vergeben: angebundene Tabelle „Organisationen“ (Verbindung '.$this->connection->name.')')
            ->assertSee('verwendet bereits angebundene Tabelle forschung_organisationen (Verbindung '.$this->connection->name.')');

        $table = CustomTable::query()->where('name', 'forschung_organisationen')->sole();
        $this->assertSame('Organisations', $table->getTranslation('label', 'en'));
        $this->assertTrue(app(DataMenu::class)->exists($table));
    }
}
