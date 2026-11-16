<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\Ui\DynamicRecordActions;
use App\DynamicData\Ui\KeylessRow;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\TableConnector\Pages\EditConnectedTable;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Zusammengesetzte Schlüssel (z. B. Zuordnungstabellen für n:m-Beziehungen). */
class TableConnectorCompositeKeyTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    private const array KEY = ['publikation_id', 'person_id'];

    private DatabaseConnection $connection;

    private ExternalResearchDatabase $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        // Die Tests schreiben in die externe Testdatenbank: vor jedem Test frisch aufbauen.
        $this->fixture = ExternalResearchDatabase::on($this->externalDatabaseName());
        $this->fixture->reset();
        $this->connection = $this->researchConnection(['is_read_only' => false]);
    }

    private function service(): TableConnectorService
    {
        return app(TableConnectorService::class);
    }

    private function external(): Connection
    {
        return $this->fixture->connection();
    }

    private function connect(string $table, string $schema = ExternalResearchDatabase::SCHEMA, bool $writable = false): CustomTable
    {
        $connected = $this->service()->connect($this->connection, $schema, $table, ['name' => $table]);

        return ($writable ? $this->service()->update($connected, ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true]) : $connected)->fresh('fields');
    }

    /** @return array{string, int} eine Publikation und eine Person, die noch nicht zugeordnet ist */
    private function freePair(): array
    {
        $publication = (string) $this->external()->table('forschung.publikationen')->orderBy('titel')->value('id');
        $assigned = $this->external()->table('forschung.publikation_autoren')->where('publikation_id', $publication)->pluck('person_id')->all();
        $person = (int) $this->external()->table('forschung.personen')->whereNotIn('id', $assigned)->orderBy('id')->value('id');

        return [$publication, $person];
    }

    public function test_composite_primary_key_is_taken_over_and_allows_writing(): void
    {
        $authors = $this->connect('publikation_autoren');

        $this->assertSame(self::KEY, $authors->keyColumns());
        $this->assertTrue($authors->hasCompositeKey());
        $this->assertFalse($authors->hasSingleKey());
        $this->assertTrue($this->service()->keyGuaranteed($authors));

        $authors = $this->service()->update($authors, ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true]);
        $this->assertTrue($authors->supports('create') && $authors->supports('update') && $authors->supports('delete'));

        // Speichern des Formulars behält beide Schlüsselspalten.
        $this->actingAs($this->superAdmin());
        Livewire::test(EditConnectedTable::class, ['record' => $authors->getRouteKey()])
            ->assertFormSet(['key_columns' => self::KEY])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(self::KEY, $authors->fresh()->key_columns);
    }

    public function test_create_update_and_delete_hit_exactly_one_assignment(): void
    {
        // Mit angebundenen Zieltabellen werden beide Spalten zu Verknüpfungen (auch die uuid-Spalte ist dann beschreibbar).
        $this->connect('publikationen');
        $this->connect('personen');
        $authors = $this->connect('publikation_autoren', writable: true);
        $this->actingAs($this->superAdmin());
        [$publication, $person] = $this->freePair();
        $before = $this->external()->table('forschung.publikation_autoren')->count();

        $record = DynamicRecord::forTable($authors)->create(['publikation_id' => $publication, 'person_id' => (string) $person, 'reihenfolge' => 9]);
        $this->assertSame($before + 1, $this->external()->table('forschung.publikation_autoren')->count());

        // Ändern betrifft nur diese Zuordnung (dieselbe Person steht auch bei anderen Publikationen).
        $record->update(['reihenfolge' => 7]);
        $this->assertSame(7, (int) $this->external()->table('forschung.publikation_autoren')->where(['publikation_id' => $publication, 'person_id' => $person])->value('reihenfolge'));
        $this->assertFalse($this->external()->table('forschung.publikation_autoren')->where('reihenfolge', 7)->where('publikation_id', '<>', $publication)->exists());

        // Protokoll: Schlüssel als kodierte Werte beider Spalten; der Verlauf findet beide Einträge.
        $key = KeylessRow::encode(['publikation_id' => $publication, 'person_id' => $person]);
        $this->assertSame(2, Activity::query()->whereNull('subject_id')->where('properties->key', $key)->count());
        $loaded = KeylessRow::constrain($authors, DynamicRecord::forTable($authors)->newQuery(), $key)->first();
        $this->assertCount(2, DynamicRecordActions::historyRows($loaded));

        // Schlüsselspalten sind fest: andere Person = löschen und neu anlegen.
        try {
            $loaded->update(['person_id' => $person + 1]);
            $this->fail('Schlüssel geändert.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $loaded->refresh()->delete();
        $this->assertSame($before, $this->external()->table('forschung.publikation_autoren')->count());
        $this->assertFalse($this->external()->table('forschung.publikation_autoren')->where(['publikation_id' => $publication, 'person_id' => $person])->exists());
    }

    public function test_data_page_offers_details_but_no_selection(): void
    {
        $authors = $this->connect('publikation_autoren', writable: true);
        $this->actingAs($this->superAdmin());
        $row = DynamicRecord::forTable($authors)->newQuery()->orderBy('reihenfolge')->first();

        Livewire::test(DynamicDataPage::class, ['table' => 'publikation_autoren'])
            ->assertTableActionVisible('details', KeylessRow::key($authors, $row));
    }

    public function test_manual_key_without_guarantee_allows_reading_only(): void
    {
        $log = $this->connect('importprotokoll', 'public');
        $this->assertSame([], $log->keyColumns());

        $log = $this->service()->update($log, ['key_columns' => ['zeitpunkt', 'quelle']]);
        $this->assertSame(['zeitpunkt', 'quelle'], $log->keyColumns());
        $this->assertTrue($log->hasKey());
        $this->assertFalse($this->service()->keyGuaranteed($log));

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('eindeutig');
        $this->service()->update($log, ['allow_update' => true]);
    }

    public function test_writing_refuses_ambiguous_rows(): void
    {
        $log = $this->connect('importprotokoll', 'public');
        // Am Table-Connector vorbei freigeschaltet: die zweite Sicherung muss greifen.
        $log->forceFill(['key_columns' => ['quelle'], 'allow_update' => true, 'allow_delete' => true])->save();
        DynamicRecord::forget('importprotokoll');

        $record = DynamicRecord::forTable($log->fresh('fields'))->newQuery()->where('quelle', 'ORCID-Abgleich')->first();

        try {
            $record->update(['zeilen' => 1]);
            $this->fail('Mehrere Zeilen geändert.');
        } catch (BusinessRuleException) {
            $this->assertFalse($this->external()->table('public.importprotokoll')->where('zeilen', 1)->exists());
        }

        $this->expectException(BusinessRuleException::class);
        $record->delete();
    }

    public function test_unknown_key_column_is_rejected(): void
    {
        $authors = $this->connect('publikation_autoren');

        $this->expectException(BusinessRuleException::class);
        $this->service()->update($authors, ['key_columns' => ['publikation_id', 'gibt_es_nicht']]);
    }
}
