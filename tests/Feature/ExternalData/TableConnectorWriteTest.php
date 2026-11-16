<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\DataAccess;
use App\DynamicData\Ui\DynamicFormBuilder;
use App\DynamicData\Ui\DynamicRecordActions;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Models\Role;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Table-Connector, Etappe 3: Anlegen, Bearbeiten und Löschen in angebundenen Tabellen. */
class TableConnectorWriteTest extends TestCase
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
        // Die Tests ändern die externe Testdatenbank: vor jedem Test frisch aufbauen.
        $this->fixture = ExternalResearchDatabase::on($this->externalDatabaseName());
        $this->fixture->reset();
        $this->connection = $this->researchConnection(['is_read_only' => false]);
    }

    private function service(): TableConnectorService
    {
        return app(TableConnectorService::class);
    }

    /**
     * @param  list<string>  $actions
     */
    private function connect(string $table, array $actions = ['create', 'update', 'delete']): CustomTable
    {
        $connected = $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);

        return $this->service()->update($connected, array_fill_keys(array_map(static fn (string $a): string => CustomTable::WRITE_ACTIONS[$a], $actions), true))->fresh('fields');
    }

    private function external(): Connection
    {
        return $this->fixture->connection();
    }

    public function test_writing_must_be_enabled_and_possible(): void
    {
        $people = $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'personen']);
        $admin = $this->superAdmin();
        $this->assertFalse(DataAccess::allows($admin, $people, 'create'));
        $this->assertTrue(DataAccess::allows($admin, $people, 'view'));

        $people = $this->service()->update($people, ['allow_create' => true]);
        $this->assertTrue(DataAccess::allows($admin, $people, 'create'));
        $this->assertFalse(DataAccess::allows($admin, $people, 'update'));

        // Verbindung auf „nur lesen“: Schreiben wirkt nicht mehr, auch wenn freigeschaltet.
        $this->connection->update(['is_read_only' => true]);
        $this->assertFalse(DataAccess::allows($admin, $people->fresh(), 'create'));

        foreach ([['personen', ['allow_update' => true]], ['projekt_uebersicht', ['allow_create' => true]]] as [$table, $flags]) {
            $connected = CustomTable::query()->where('name', $table)->first()
                ?? $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);

            try {
                $this->service()->update($connected, $flags);
                $this->fail("Schreiben freigeschaltet für {$table}.");
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_create_with_identity_key_returns_the_new_key_and_logs_it(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('organisationen');

        Livewire::test(DynamicDataPage::class, ['table' => 'organisationen'])
            ->mountTableAction('create')
            ->assertTableActionDataSet(['name' => null])
            ->setTableActionData(['name' => 'Hochschule Luzern', 'land' => 'CH', 'typ' => 'hochschule', 'aktiv' => true])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $row = $this->external()->table('forschung.organisationen')->where('name', 'Hochschule Luzern')->first();
        $this->assertNotNull($row);
        $this->assertSame(9, (int) $row->id);

        $activity = Activity::query()->where('subject_type', 'dynamic:organisationen')->where('event', 'created')->sole();
        $this->assertSame(9, (int) $activity->subject_id);
        $this->assertSame('Hochschule Luzern', $activity->attribute_changes['attributes']['name']);
    }

    public function test_generated_read_only_and_text_only_columns_are_not_in_the_form(): void
    {
        $this->actingAs($this->superAdmin());
        $people = $this->connect('personen');
        $this->service()->updateField($people->fields->firstWhere('name', 'orcid'), ['is_read_only' => true]);

        $component = Livewire::test(DynamicDataPage::class, ['table' => 'personen'])->mountTableAction('create');

        foreach (['vorname', 'nachname', 'email', 'geburtsdatum'] as $visible) {
            $component->assertSee($visible === 'geburtsdatum' ? 'Geburtsdatum' : ucfirst($visible === 'email' ? 'Email' : $visible));
        }

        $form = app(DynamicFormBuilder::class)->components($people->fresh('fields'));
        $names = array_map(static fn ($c): string => $c->getName(), $form);
        $this->assertNotContains('id', $names);
        $this->assertNotContains('vollname', $names);
        $this->assertNotContains('orcid', $names);
        // Spalte mit Vorgabewert (now()) bleibt bearbeitbar; leer übernimmt die Datenbank den Vorgabewert.
        $this->assertContains('erstellt_am', $names);
        $this->assertContains('vorname', $names);
    }

    public function test_uuid_key_is_returned_and_history_works(): void
    {
        $publications = $this->connect('publikationen');
        $this->actingAs($this->superAdmin());

        $record = DynamicRecord::forTable($publications)->create(['titel' => 'Neue Studie', 'jahr' => 2026, 'typ' => 'artikel', 'open_access' => true]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $record->getKey());

        $record->update(['titel' => 'Neue Studie (überarbeitet)']);
        $activities = Activity::query()->where('subject_type', 'dynamic:publikationen')->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $activities->pluck('event')->all());
        $this->assertNull($activities[0]->subject_id);
        $this->assertSame((string) $record->getKey(), $activities[0]->properties['key']);

        $history = DynamicRecordActions::historyRows($record);
        $this->assertCount(2, $history);
        $this->assertStringContainsString('überarbeitet', $history[0]['what']);
    }

    public function test_natural_key_is_entered_on_create_and_fixed_afterwards(): void
    {
        $this->actingAs($this->superAdmin());
        $countries = $this->connect('laender');

        Livewire::test(DynamicDataPage::class, ['table' => 'laender'])
            ->callTableAction('create', data: ['code' => 'IT', 'name' => 'Italien'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Italien', $this->external()->table('forschung.laender')->where('code', 'IT')->value('name'));

        $italy = DynamicRecord::forTable($countries)->newQuery()->findOrFail('IT');
        Livewire::test(DynamicDataPage::class, ['table' => 'laender'])
            ->mountTableAction('details', $italy)
            ->mountAction('edit')
            ->assertActionDataSet(['code' => 'IT'])
            ->setActionData(['code' => 'XX', 'name' => 'Italia'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('Italia', $this->external()->table('forschung.laender')->where('code', 'IT')->value('name'));
        $this->assertFalse($this->external()->table('forschung.laender')->where('code', 'XX')->exists());
    }

    public function test_validation_from_the_schema(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('organisationen');

        Livewire::test(DynamicDataPage::class, ['table' => 'organisationen'])
            ->callTableAction('create', data: ['name' => 'ETH Zürich', 'land' => 'CHE', 'typ' => null])
            ->assertHasTableActionErrors(['name' => 'unique', 'land' => 'max', 'typ' => 'required']);

        // CHECK-Regel der Datenbank: Verstoß kommt als Datenbankfehler und wird zentral übersetzt.
        try {
            DynamicRecord::forTable(CustomTable::query()->where('name', 'organisationen')->sole())->create(['name' => 'X', 'land' => 'CH', 'typ' => 'verein']);
            $this->fail('CHECK-Regel nicht gegriffen.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_relations_are_selectable_and_checked_against_the_target(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('personen', []);
        $this->connect('projekte');

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callTableAction('create', data: ['kurzname' => 'P-99', 'titel' => 'Neues Projekt', 'beginn' => '2026-11-01', 'projektleiter_id' => 999])
            ->assertHasTableActionErrors(['projektleiter_id']);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callTableAction('create', data: ['kurzname' => 'P-99', 'titel' => 'Neues Projekt', 'beginn' => '2026-11-01', 'projektleiter_id' => 3])
            ->assertHasNoTableActionErrors();

        $this->assertSame(3, (int) $this->external()->table('forschung.projekte')->where('kurzname', 'P-99')->value('projektleiter_id'));
    }

    public function test_delete_is_permanent_and_guarded(): void
    {
        $people = $this->connect('personen');
        $this->connect('projekte');
        $this->actingAs($this->superAdmin());

        // Person 1 leitet ein Projekt: die angebundene Verknüpfung sperrt das Löschen.
        try {
            DynamicRecord::forTable($people->fresh('fields'))->newQuery()->findOrFail(1)->delete();
            $this->fail('Löschen trotz Projekt möglich.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        // Eine Person ohne Bezüge lässt sich löschen – endgültig.
        $unused = $this->external()->table('forschung.personen')->insertGetId(['vorname' => 'Ohne', 'nachname' => 'Bezug']);
        DynamicRecord::forTable($people->fresh('fields'))->newQuery()->findOrFail($unused)->delete();
        $this->assertFalse($this->external()->table('forschung.personen')->where('id', $unused)->exists());

        $author = (int) $this->external()->table('forschung.publikation_autoren')->value('person_id');
        $leaders = $this->external()->table('forschung.projekte')->pluck('projektleiter_id')->map(fn ($id): int => (int) $id)->all();

        if (! in_array($author, $leaders, true)) {
            try {
                DynamicRecord::forTable($people->fresh('fields'))->newQuery()->findOrFail($author)->delete();
                $this->fail('Fremdschlüssel nicht gegriffen.');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        }

        $this->assertSame(1, Activity::query()->where('subject_type', 'dynamic:personen')->where('event', 'deleted')->count());
    }

    public function test_role_matrix_offers_enabled_write_actions(): void
    {
        $this->actingAs($this->superAdmin());
        $this->connect('laender', ['create', 'update']);
        $role = Role::create(['name' => 'pflege', 'guard_name' => 'web']);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['data_permissions.laender' => ['view', 'create', 'update']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->fillForm(['data_permissions.laender' => ['view', 'delete']])
            ->call('save')
            ->assertHasFormErrors(['data_permissions.laender.1']);

        $this->assertEqualsCanonicalizing(['data.laender.create', 'data.laender.update', 'data.laender.view'], $role->fresh()->permissions->pluck('name')->all());
    }
}
