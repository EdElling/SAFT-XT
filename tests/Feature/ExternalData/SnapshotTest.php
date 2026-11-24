<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\DataPermissions;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\SchemaVerifier;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\Snapshots\SnapshotService;
use App\ExternalData\TableConnectorService;
use App\Filament\Resources\Reports\Pages\ReportDesigner;
use App\Filament\Resources\TableConnector\Pages\EditConnectedTable;
use App\Filament\Resources\TableConnector\RelationManagers\SnapshotsRelationManager;
use App\Models\CustomTable;
use App\Models\DataSnapshot;
use App\Models\User;
use App\Models\WorkItem;
use App\Reports\Editor\FieldCatalog;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportRunner;
use App\Services\DynamicSchemaService;
use App\Work\TimeLimit;
use App\Work\Types\SnapshotRefreshWork;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Database\Seeders\ExternalResearchDatabase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Datenstände angebundener Tabellen: unveränderliche Kopien in app_data. */
class SnapshotTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    protected array $connectionsToTransact = [null, 'app_data'];

    private ExternalResearchDatabase $fixture;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->fixture = $this->prepareExternalDatabase();
        $this->connectResearchDemoTables($this->researchConnection());
        $this->admin = $this->superAdmin();
    }

    public function test_refresh_copies_the_table_and_reports_newest_complete(): void
    {
        $people = $this->table('fdb_personen');
        $snapshot = $this->service()->refresh($people, $this->admin);

        $this->assertTrue($snapshot->isComplete());
        $this->assertSame(20, $snapshot->row_count);
        $this->assertSame(20, $this->rows($people, $snapshot));
        $this->assertSame($snapshot->id, $people->latestSnapshot()?->id);

        $copy = $this->copy($people)->where(SnapshotService::SNAPSHOT_COLUMN, $snapshot->id)->orderBy('id')->first();
        $this->assertSame('Anna', $copy->vorname);
        $this->assertSame(['stand' => $snapshot->id, 'zeilen' => 20], Activity::query()->where('event', 'snapshot_created')->sole()->attribute_changes['attributes']);

        // Zweiter Stand: beide bleiben (Aufbewahrung 5), Auswertungen nehmen den neuesten.
        $second = $this->service()->refresh($people, $this->admin);
        $this->assertSame(40, $this->copy($people)->count());
        $this->assertSame($second->id, $people->latestSnapshot()?->id);

        $this->assertSame([], app(SchemaVerifier::class)->issues(), 'Kopie ist keine fremde Tabelle für den Table-Designer.');
    }

    public function test_retention_keeps_the_last_n_and_always_the_newest(): void
    {
        $people = $this->table('fdb_personen');
        $people->update(['snapshot_keep' => 2]);

        $ids = array_map(fn (): int => $this->service()->refresh($people->fresh(), $this->admin)->id, range(1, 3));

        $this->assertSame([$ids[1], $ids[2]], $people->snapshots()->orderBy('id')->pluck('id')->all());
        $this->assertSame(40, $this->copy($people)->count(), 'Zeilen des ältesten Stands entfernt.');

        // Frist: ältere als einen Tag weg – der neueste bleibt immer.
        $people->update(['snapshot_max_days' => 1]);
        DataSnapshot::query()->whereKey($ids[1])->update(['finished_at' => now()->subDays(3)]);
        DataSnapshot::query()->whereKey($ids[2])->update(['finished_at' => now()->subDays(2)]);
        $this->service()->prune($people->fresh());
        $this->assertSame([$ids[2]], $people->snapshots()->pluck('id')->all());
    }

    public function test_failure_keeps_the_previous_snapshot(): void
    {
        $people = $this->table('fdb_personen');
        $good = $this->service()->refresh($people, $this->admin);
        config(['reports.snapshot_max_rows' => 5]);

        try {
            $this->service()->refresh($people, $this->admin);
            $this->fail('Abbruch erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Die Tabelle hat mehr als 5 Zeilen – zu gross für einen Datenstand.', $e->getMessage());
        }

        $this->assertSame($good->id, $people->latestSnapshot()?->id);
        $this->assertSame(20, $this->copy($people)->count(), 'Keine halben Stände.');
        $failed = $people->snapshots()->where('status', DataSnapshot::FAILED)->sole();
        $this->assertStringContainsString('mehr als 5 Zeilen', $failed->message, 'Fehler bleibt sichtbar bis zum nächsten Stand.');

        config(['reports.snapshot_max_rows' => 1000]);
        $this->service()->refresh($people, $this->admin);
        $this->assertSame(0, $people->snapshots()->where('status', DataSnapshot::FAILED)->count(), 'Ein erfolgreicher Stand räumt Fehlversuche weg.');
    }

    public function test_new_source_column_is_added_and_old_snapshots_keep_their_data(): void
    {
        $orgs = $this->table('fdb_organisationen');
        $this->service()->refresh($orgs, $this->admin);

        $schema = $this->fixture->connection()->getSchemaBuilder();
        $source = ExternalResearchDatabase::SCHEMA.'.organisationen';
        $dropColumn = function () use ($schema, $source): void {
            if ($schema->hasColumn($source, 'kuerzel')) {
                $schema->table($source, fn (Blueprint $t) => $t->dropColumn('kuerzel'));
            }
        };
        $dropColumn();

        try {
            $schema->table($source, fn (Blueprint $t) => $t->string('kuerzel', 20)->nullable());
            $this->fixture->connection()->table($source)->update(['kuerzel' => 'K']);
            app(TableConnectorService::class)->refresh($orgs->fresh());
            $second = $this->service()->refresh($orgs->fresh(), $this->admin);

            $this->assertSame(['K'], $this->copy($orgs)->where(SnapshotService::SNAPSHOT_COLUMN, $second->id)->distinct()->pluck('kuerzel')->all());
            $this->assertSame([null], $this->copy($orgs)->where(SnapshotService::SNAPSHOT_COLUMN, '<>', $second->id)->distinct()->pluck('kuerzel')->all());
        } finally {
            $dropColumn();
        }
    }

    public function test_delete_and_disconnect_remove_snapshots(): void
    {
        $people = $this->table('fdb_personen');
        $snapshot = $this->service()->refresh($people, $this->admin);
        $this->service()->delete($snapshot, $this->admin);
        $this->assertNull($people->latestSnapshot());
        $this->assertSame(0, $this->copy($people)->count());

        $this->service()->refresh($people, $this->admin);
        app(TableConnectorService::class)->disconnect($people->fresh());
        $this->assertFalse(app(DynamicDataNamespace::class)->connection()->getSchemaBuilder()->hasTable(app(SnapshotService::class)->qualified($people)));
        $this->assertSame(0, DataSnapshot::query()->count());
    }

    public function test_designer_tables_cannot_use_the_snapshot_prefix(): void
    {
        $this->expectExceptionMessage('ist für Systemobjekte reserviert');
        app(DynamicSchemaService::class)->createTable(['name' => 'ds_kopie', 'label' => 'Kopie']);
    }

    public function test_reports_read_the_newest_snapshot_and_join_over_the_key(): void
    {
        $definition = ReportDefinition::fromArray([
            'base' => $this->table('fdb_personen')->id,
            'fields' => [$this->ref('land', 'fdb_organisationen.land', ['fdb_personen.arbeitgeber_id'])],
            'groups' => ['land'],
            'measures' => [['key' => 'n', 'agg' => 'count']],
        ]);
        $runner = app(ReportRunner::class);

        try {
            $runner->run($definition, $this->admin);
            $this->fail('Ohne Datenstand nicht ausführbar');
        } catch (InvalidReportException $e) {
            $this->assertStringContainsString('ohne Datenstand', $e->errors[0]);
        }
        // In der Auswahl schon sichtbar, Felder gibt es erst mit Datenstand.
        $this->assertArrayHasKey($this->table('fdb_personen')->id, app(FieldCatalog::class)->baseTables($this->admin));
        $this->assertSame([], app(FieldCatalog::class)->entries($this->table('fdb_personen')->id, $this->admin));

        $this->service()->refresh($this->table('fdb_personen'), $this->admin);
        $orgs = $this->service()->refresh($this->table('fdb_organisationen'), $this->admin);
        $this->assertNotSame([], (new FieldCatalog)->entries($this->table('fdb_personen')->id, $this->admin));

        $source = ExternalResearchDatabase::SCHEMA;
        $expected = $this->fixture->connection()->table($source.'.personen as p')
            ->leftJoin($source.'.organisationen as o', 'o.id', '=', 'p.arbeitgeber_id')
            ->selectRaw('o.land as land, count(*) as n')->groupBy('o.land')->orderBy('o.land')
            ->get()->map(fn (object $r): array => ['land' => $r->land, 'n' => (int) $r->n])->all();

        // Reihenfolge von NULL unterscheidet sich je Datenbank: nach Land vergleichen.
        $byLand = fn (array $rows): array => collect($rows)->mapWithKeys(fn (array $r): array => [(string) $r['land'] => $r['n']])->sortKeys()->all();
        $result = $runner->run($definition, $this->admin);
        $this->assertSame($byLand($expected), $byLand($result->rows));
        $this->assertSame($orgs->id, $result->report->snapshots[$this->table('fdb_organisationen')->id]->id);

        // Änderungen der Quelle wirken erst mit dem nächsten Datenstand.
        $people = $this->fixture->connection()->table($source.'.personen');
        $original = (clone $people)->whereNotNull('arbeitgeber_id')->orderBy('id')->first(['id', 'arbeitgeber_id']);

        try {
            (clone $people)->where('id', $original->id)->update(['arbeitgeber_id' => null]);
            $this->assertSame($byLand($expected), $byLand($runner->run($definition, $this->admin)->rows));

            $this->service()->refresh($this->table('fdb_personen'), $this->admin);
            $counts = collect($runner->run($definition, $this->admin)->rows)->pluck('n', 'land');
            $this->assertSame(1 + (int) collect($expected)->pluck('n', 'land')->get(''), (int) $counts->get(''), 'Neuer Stand: eine Person ohne Arbeitgeber mehr.');
        } finally {
            (clone $people)->where('id', $original->id)->update(['arbeitgeber_id' => $original->arbeitgeber_id]);
        }
    }

    public function test_field_missing_in_the_snapshot_is_reported(): void
    {
        $people = $this->table('fdb_personen');
        $this->service()->refresh($people, $this->admin);
        app(DynamicDataNamespace::class)->connection()->getSchemaBuilder()
            ->table(app(SnapshotService::class)->qualified($people), fn (Blueprint $t) => $t->dropColumn('nachname'));

        $this->expectExceptionMessage('ist im aktuellen Datenstand von');
        app(ReportRunner::class)->run(ReportDefinition::fromArray([
            'base' => $people->id,
            'fields' => [$this->ref('name', 'fdb_personen.nachname')],
        ]), $this->admin);
    }

    /** Als Arbeit in Schritten: Keyset-Cursor über den Schlüssel, Zeilen und Fortschritt je Schritt; Abbruch verwirft den Stand. */
    public function test_snapshot_is_copied_in_steps_and_cancelling_discards_it(): void
    {
        config(['reports.snapshot_chunk' => 7]);
        $people = $this->table('fdb_personen');
        $good = $this->service()->refresh($people, $this->admin);
        $this->assertSame(20, $good->row_count);
        $this->assertSame([WorkItem::DONE, 20], [$good->work->status, $good->work->done]);

        // Budget 0 (TimeLimit gefälscht, denn das Budget wird sonst auf 1 s aufgerundet): je Aufruf ein Block von 7 Zeilen.
        app()->instance(TimeLimit::class, new class extends TimeLimit
        {
            public function budget(float $wanted, int $margin = 5): float
            {
                return 0.0;
            }
        });
        $runner = app(WorkRunner::class);
        $item = $this->service()->start($people, $this->admin);
        $snapshot = DataSnapshot::query()->where('work_item_id', $item->id)->sole();
        $this->assertSame([WorkItem::RUNNING, 7, 20, 7, DataSnapshot::RUNNING], [$item->status, $item->done, $item->total, $item->state['rows'], $snapshot->status]);
        $this->assertSame(array_values($people->keyColumns()), array_keys($item->state['after']), 'Cursor sind die Schlüsselwerte der letzten Zeile.');
        $this->assertSame(7, $this->copy($people)->where(SnapshotService::SNAPSHOT_COLUMN, $snapshot->id)->count());
        $this->assertTrue($this->service()->busy($people));
        $this->assertSame($item->id, $this->service()->openWork($people)?->id);

        try {
            $this->service()->start($people, $this->admin);
            $this->fail('Beschäftigt erwartet.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(SnapshotService::BUSY, $e->errorCode);
        }

        $item = $runner->advance($item, 0);
        $this->assertSame(14, $this->copy($people)->where(SnapshotService::SNAPSHOT_COLUMN, $snapshot->id)->count(), 'Keine doppelten Zeilen.');

        app(WorkService::class)->cancel($item);
        $snapshot->refresh();
        $this->assertSame([DataSnapshot::FAILED, 'Abgebrochen.'], [$snapshot->status, $snapshot->message]);
        $this->assertSame(0, $this->copy($people)->where(SnapshotService::SNAPSHOT_COLUMN, $snapshot->id)->count(), 'Der halbe Stand ist weg.');
        $this->assertSame($good->id, $people->latestSnapshot()?->id, 'Der vorherige bleibt gültig.');
        $this->assertFalse($this->service()->busy($people));

        // Zu Ende getrieben: drei Blöcke, dann der Rest.
        $done = $runner->advance($this->service()->start($people, $this->admin), 30);
        $this->assertSame([WorkItem::DONE, 'Datenstand gezogen: 20 Zeilen', 20], [$done->status, $done->message, $done->done]);
        $this->assertSame(20, $people->latestSnapshot()?->row_count);
    }

    /** Reicht das Budget nicht, läuft der Stand im Hintergrund weiter: Hinweis mit Link, Status „läuft“ verweist auf die Arbeit. */
    public function test_snapshot_tab_reports_a_snapshot_that_continues_in_the_background(): void
    {
        $this->actingAs($this->admin);
        config(['reports.snapshot_chunk' => 7]);
        app()->instance(TimeLimit::class, new class extends TimeLimit
        {
            public function budget(float $wanted, int $margin = 5): float
            {
                return 0.0;
            }
        });
        $people = $this->table('fdb_personen');
        $tab = fn () => Livewire::test(SnapshotsRelationManager::class, ['ownerRecord' => $people->fresh(), 'pageClass' => EditConnectedTable::class]);

        $tab()->callTableAction('refreshSnapshot')->assertNotified('Der Datenstand wird im Hintergrund gezogen; die Liste zeigt ihn, sobald er vollständig ist.');
        $snapshot = $people->snapshots()->sole();
        $this->assertSame([DataSnapshot::RUNNING, WorkItem::RUNNING], [$snapshot->status, $snapshot->work->status]);
        $tab()->assertSee('läuft');

        // Noch einmal: beschäftigt.
        $tab()->callTableAction('refreshSnapshot')->assertNotified('Diese Tabelle wird gerade aktualisiert. Bitte später noch einmal versuchen.');

        // Der Scheduler zieht den Stand zu Ende.
        $this->travel(1)->minutes();
        $this->artisan('work:run')->assertSuccessful();
        $this->assertSame([DataSnapshot::COMPLETE, 20], [$snapshot->fresh()->status, $snapshot->fresh()->row_count]);
        $this->assertSame(1, Activity::query()->where('event', 'snapshot_created')->count());
    }

    public function test_snapshot_tab_refreshes_sets_retention_and_deletes(): void
    {
        $this->actingAs($this->admin);
        $people = $this->table('fdb_personen');
        $tab = fn () => Livewire::test(SnapshotsRelationManager::class, ['ownerRecord' => $people->fresh(), 'pageClass' => EditConnectedTable::class]);

        $tab()->assertSee('Noch kein Datenstand')
            ->callTableAction('refreshSnapshot')
            ->assertNotified('Datenstand gezogen: 20 Zeilen');
        $snapshot = $people->latestSnapshot();
        $tab()->assertSee('vollständig')->assertSee('20');

        $tab()->callTableAction('retention', data: ['snapshot_keep' => 0])->assertHasTableActionErrors(['snapshot_keep']);
        $tab()->callTableAction('retention', data: ['snapshot_keep' => 3, 'snapshot_max_days' => 30])->assertHasNoTableActionErrors();
        $this->assertSame([3, 30], [$people->fresh()->snapshot_keep, $people->fresh()->snapshot_max_days]);

        $tab()->callTableAction('deleteSnapshot', $snapshot)->assertNotified('Datenstand gelöscht');
        $this->assertNull($people->latestSnapshot());

        // Nur Leserecht: Liste ja, Ändern nein.
        $this->actingAs($this->userWithPermissions(['table_connector.view']));
        $tab()->assertTableActionHidden('refreshSnapshot')->assertTableActionHidden('retention');
    }

    public function test_designer_lists_connected_tables_and_creates_missing_snapshots_with_read_permission(): void
    {
        $projects = $this->table('fdb_projekte');
        // Leserecht auf Projekte und Personen, nicht auf Organisationen; kein Recht im Table-Connector.
        $user = $this->userWithPermissions(['reports.view', 'reports.create', DataPermissions::name('fdb_projekte', 'view'), DataPermissions::name('fdb_personen', 'view')]);
        $this->actingAs($user);

        $options = collect(app(FieldCatalog::class)->baseTableOptions($user))->keyBy('name');
        $this->assertSame(['fdb_personen', 'fdb_projekte'], $options->keys()->sort()->values()->all());
        $this->assertSame(FieldCatalog::KIND_EXTERNAL, $options['fdb_projekte']['kind']);
        $this->assertSame('Forschung', $options['fdb_projekte']['connection']);

        Livewire::test(ReportDesigner::class)
            ->assertSee('Forschung · noch kein Datenstand')
            ->call('setBase', $projects->id)
            ->assertSet('state.base', $projects->id)
            ->assertNotified('Datenstand erzeugt: Projekte, Personen')
            ->assertSee('Projektleiter');

        $this->assertNotNull($projects->latestSnapshot());
        $this->assertNotNull($this->table('fdb_personen')->latestSnapshot());
        $this->assertNull($this->table('fdb_organisationen')->latestSnapshot(), 'Ohne Leserecht kein Stand.');
        $this->assertSame($user->id, $projects->latestSnapshot()->created_by);

        $options = collect((new FieldCatalog)->baseTableOptions($user))->keyBy('name');
        $this->assertSame(FieldCatalog::KIND_SNAPSHOT, $options['fdb_projekte']['kind']);
    }

    public function test_designer_asks_before_refreshing_a_stale_snapshot(): void
    {
        $this->actingAs($this->admin);
        $people = $this->table('fdb_personen');
        $orgs = $this->table('fdb_organisationen');
        $old = $this->service()->refresh($people, $this->admin);
        $this->service()->refresh($orgs, $this->admin);
        $old->update(['finished_at' => now()->subHours(30)]);

        // „Vorhandenen verwenden“: kein neuer Stand, Tabelle gesetzt.
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $people->id)
            ->assertActionMounted('refreshSnapshots')
            ->tap(fn ($designer) => $this->assertStringContainsString('Personen – Stand vom', (string) $designer->instance()->refreshSnapshotsAction()->getModalDescription()))
            ->assertSet('state.base', null)
            ->callAction(TestAction::make('useExisting'))
            ->assertSet('state.base', $people->id);
        $this->assertSame($old->id, $people->latestSnapshot()->id);

        // „Neuen Stand erzeugen“: nur der veraltete wird erneuert.
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $people->id)
            ->callMountedAction()
            ->assertSet('state.base', $people->id)
            ->assertNotified('Datenstand erzeugt: Personen');
        $this->assertNotSame($old->id, $people->latestSnapshot()->id);
        $this->assertSame(1, $orgs->snapshots()->count());

        // Frisch: kein Dialog.
        Livewire::test(ReportDesigner::class)->call('setBase', $people->id)->assertActionNotMounted('refreshSnapshots')->assertSet('state.base', $people->id);

        // Wechsel mit Feldern zu einer verknüpften Tabelle mit altem Stand: erst „Grundtabelle wechseln?“, dann der Datenstand-Dialog.
        $orgs->snapshots()->update(['finished_at' => now()->subHours(30)]);
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $people->id)
            // Organisationen sind von Personen aus erreichbar: schon hier wird gefragt.
            ->assertActionMounted('refreshSnapshots')
            ->callAction(TestAction::make('useExisting'))
            ->call('add', 'rows', FieldCatalog::ref([], $people->fields()->where('name', 'nachname')->value('id')))
            ->call('setBase', $orgs->id)
            ->assertActionMounted('confirmBaseChange')
            ->callMountedAction()
            ->assertActionMounted('refreshSnapshots')
            ->callMountedAction()
            ->assertSet('state.base', $orgs->id)
            ->assertNotified('Datenstand erzeugt: Organisationen');
    }

    private function service(): SnapshotService
    {
        return app(SnapshotService::class);
    }

    private function table(string $name): CustomTable
    {
        return CustomTable::query()->where('name', $name)->sole();
    }

    private function copy(CustomTable $table): Builder
    {
        return app(DynamicDataNamespace::class)->connection()->table(app(SnapshotService::class)->qualified($table));
    }

    private function rows(CustomTable $table, DataSnapshot $snapshot): int
    {
        return $this->copy($table)->where(SnapshotService::SNAPSHOT_COLUMN, $snapshot->id)->count();
    }

    /**
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    private function ref(string $key, string $field, array $path = []): array
    {
        $id = fn (string $qualified): int => (int) $this->table(explode('.', $qualified)[0])->fields()->where('name', explode('.', $qualified)[1])->value('id');

        return ['key' => $key, 'path' => array_map($id, $path), 'field' => $id($field), 'bucket' => null, 'label' => null];
    }
}
