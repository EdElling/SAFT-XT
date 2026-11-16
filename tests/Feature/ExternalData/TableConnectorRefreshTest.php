<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\SchemaComparison;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\TableConnector\Pages\EditConnectedTable;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Table-Connector, Etappe 5: Abgleich mit dem Schema und „Neu einlesen“. */
class TableConnectorRefreshTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    private DatabaseConnection $connection;

    private ExternalResearchDatabase $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        // Die Tests ändern das Schema der externen Testdatenbank: vor jedem Test frisch aufbauen.
        $this->fixture = ExternalResearchDatabase::on($this->externalDatabaseName());
        $this->fixture->reset();
        $this->connection = $this->researchConnection(['is_read_only' => false]);
    }

    protected function tearDown(): void
    {
        // Nachfolgende Testklassen erwarten den ursprünglichen Aufbau.
        $this->fixture->reset();
        parent::tearDown();
    }

    private function service(): TableConnectorService
    {
        return app(TableConnectorService::class);
    }

    private function connect(string $table): CustomTable
    {
        return $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table])->fresh('fields');
    }

    private function alter(string $table, \Closure $change): void
    {
        $this->fixture->connection()->getSchemaBuilder()->table(ExternalResearchDatabase::SCHEMA.'.'.$table, $change);
    }

    private function field(CustomTable $table, string $name): ?CustomField
    {
        return $table->fresh('fields')->fields->firstWhere('name', $name);
    }

    public function test_freshly_connected_tables_show_no_differences(): void
    {
        $this->connectResearchDemoTables($this->connection);
        $this->connect('laender');

        foreach (CustomTable::query()->external()->get() as $table) {
            $this->assertSame([], app(SchemaComparison::class)->compare($table)->lines(), $table->name);
        }

        $this->artisan('table-connector:verify')->assertSuccessful();
    }

    public function test_new_missing_and_changed_columns_are_detected_and_applied(): void
    {
        $organisations = $this->connect('organisationen');
        $this->alter('organisationen', function (Blueprint $t): void {
            $t->string('kuerzel', 20)->nullable();
            $t->dropColumn('gegruendet');
            $t->string('webseite', 500)->nullable()->change();
        });

        $difference = app(SchemaComparison::class)->compare($organisations);
        $this->assertSame(['kuerzel'], array_map(fn ($c): string => $c->name, $difference->added));
        $this->assertSame(['gegruendet'], array_map(fn (CustomField $f): string => $f->name, $difference->missing));
        $this->assertSame([['webseite', ['type']]], array_map(fn (array $c): array => [$c['field']->name, $c['changes']], $difference->changed));
        $this->assertSame(3, $difference->count());

        // Vor dem Einlesen: verständlicher Hinweis statt Datenbankfehler.
        $this->actingAs($this->superAdmin());
        Livewire::test(DynamicDataPage::class, ['table' => 'organisationen'])
            ->assertSee('hat sich geändert');
        $this->artisan('table-connector:verify')->assertFailed();

        $this->assertSame([], $this->service()->refresh($organisations));

        $new = $this->field($organisations, 'kuerzel');
        $this->assertTrue($new->is_active);
        $this->assertTrue($new->show_in_table);
        $this->assertTrue($new->optionsArray()[TableConnectorService::NEW]);
        $this->assertSame(FieldType::String, $new->type);

        $missing = $this->field($organisations, 'gegruendet');
        $this->assertFalse($missing->is_active);
        $this->assertTrue($missing->optionsArray()[TableConnectorService::MISSING]);
        $this->assertSame(500, (int) $this->field($organisations, 'webseite')->optionsArray()['length']);

        $this->assertTrue(app(SchemaComparison::class)->compare($organisations->fresh())->isEmpty());
        Livewire::test(DynamicDataPage::class, ['table' => 'organisationen'])
            ->assertDontSee('hat sich geändert')
            ->assertSee('ETH Zürich');

        // Bearbeiten nimmt die Markierung „neu“; eine fehlende Spalte bleibt ausgeblendet.
        $this->service()->updateField($new, ['label' => ['de' => 'Kürzel']]);
        $this->assertArrayNotHasKey(TableConnectorService::NEW, $new->fresh()->optionsArray());
        $this->service()->updateField($missing, ['is_active' => true]);
        $this->assertFalse($missing->fresh()->is_active);
    }

    public function test_returning_column_is_shown_again_and_missing_one_can_be_removed(): void
    {
        $organisations = $this->connect('organisationen');
        $this->alter('organisationen', fn (Blueprint $t) => $t->dropColumn(['gegruendet', 'webseite']));
        $this->service()->refresh($organisations);

        $this->alter('organisationen', fn (Blueprint $t) => $t->date('gegruendet')->nullable());
        $difference = app(SchemaComparison::class)->compare($organisations);
        $this->assertSame(['gegruendet'], array_map(fn (CustomField $f): string => $f->name, $difference->returned));
        $this->service()->refresh($organisations);

        $back = $this->field($organisations, 'gegruendet');
        $this->assertTrue($back->is_active);
        $this->assertArrayNotHasKey(TableConnectorService::MISSING, $back->optionsArray());

        try {
            $this->service()->removeMissingField($back);
            $this->fail('Vorhandene Spalte entfernt.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $this->service()->removeMissingField($this->field($organisations, 'webseite'));
        $this->assertNull($this->field($organisations, 'webseite'));
    }

    public function test_missing_display_field_is_replaced_in_relations(): void
    {
        $organisations = $this->connect('organisationen');
        $people = $this->connect('personen');
        $employer = $this->field($people, 'arbeitgeber_id');
        $this->service()->setRelation($employer, 'organisationen', ['name', 'land'], separator: ', ');

        // Die Übersichts-View hängt an organisationen.name.
        $this->fixture->connection()->statement('drop view '.ExternalResearchDatabase::SCHEMA.'.projekt_uebersicht');
        $this->alter('organisationen', fn (Blueprint $t) => $t->dropColumn('name'));
        $notices = $this->service()->refresh($organisations);

        $this->assertCount(1, $notices);
        $this->assertSame(['land'], RelationDisplay::fields($employer->fresh()->optionsArray()));

        // Fällt auch das letzte Anzeigefeld weg, gilt der Vorschlag.
        $this->alter('organisationen', fn (Blueprint $t) => $t->dropColumn('land'));
        $this->service()->refresh($organisations);
        $this->assertSame(['typ'], RelationDisplay::fields($employer->fresh()->optionsArray()));
    }

    public function test_lost_primary_key_disables_writing_but_keeps_the_key(): void
    {
        $organisations = $this->connect('organisationen');
        $organisations = $this->service()->update($organisations, ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true]);
        $people = $this->connect('personen');

        $this->alter('personen', fn (Blueprint $t) => $t->dropForeign(['arbeitgeber_id']));
        $this->alter('projekte', fn (Blueprint $t) => $t->dropForeign(['partner_id']));
        $this->alter('organisationen', fn (Blueprint $t) => $t->dropPrimary('organisationen_pkey'));

        $difference = app(SchemaComparison::class)->compare($organisations);
        $this->assertNull($difference->keyColumns);
        $this->assertTrue($difference->keyNotUnique);
        $notices = $this->service()->refresh($organisations);

        // Die Spalte bleibt Schlüssel zum Lesen; Verknüpfungen auf die Tabelle bleiben.
        $organisations = $organisations->fresh();
        $this->assertSame(['id'], $organisations->key_columns);
        $this->assertFalse($organisations->allow_create || $organisations->allow_update || $organisations->allow_delete);
        $this->assertSame(FieldType::Relation, $this->field($people, 'arbeitgeber_id')->type);
        $this->assertCount(1, $notices);
        $this->assertTrue(app(SchemaComparison::class)->compare($organisations)->isEmpty());

        // Fehlt die Schlüsselspalte selbst, entfällt der Schlüssel.
        $countries = $this->connect('laender');
        $this->alter('laender', fn (Blueprint $t) => $t->dropColumn('code'));
        $this->assertSame([], app(SchemaComparison::class)->compare($countries)->keyColumns);
        $this->service()->refresh($countries);
        $this->assertSame([], $countries->fresh()->key_columns);
    }

    public function test_missing_table_changes_nothing(): void
    {
        $authors = $this->connect('publikation_autoren');
        $this->fixture->connection()->getSchemaBuilder()->rename(ExternalResearchDatabase::SCHEMA.'.publikation_autoren', 'publikation_autoren_alt');

        $this->assertTrue(app(SchemaComparison::class)->compare($authors)->tableMissing);

        try {
            $this->service()->refresh($authors);
            $this->fail('Fehlende Tabelle eingelesen.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($authors->fresh('fields')->fields->every(fn (CustomField $f): bool => $f->is_active));
        $this->artisan('table-connector:verify', ['table' => ['publikation_autoren']])->assertFailed();
    }

    public function test_edit_page_shows_differences_and_refreshes(): void
    {
        $organisations = $this->connect('organisationen');
        $this->actingAs($this->superAdmin());

        Livewire::test(EditConnectedTable::class, ['record' => $organisations->getRouteKey()])
            ->assertDontSee('Die externe Tabelle hat sich geändert');

        $this->alter('organisationen', fn (Blueprint $t) => $t->string('kuerzel', 20)->nullable());

        Livewire::test(EditConnectedTable::class, ['record' => $organisations->getRouteKey()])
            ->assertSee('Die externe Tabelle hat sich geändert')
            ->assertSee('Neue Spalte „kuerzel“')
            ->callAction('refreshSchema')
            ->assertHasNoActionErrors()
            ->assertNotified('Schema übernommen');

        $this->assertNotNull($this->field($organisations, 'kuerzel'));
    }
}
