<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\ExternalData\Snapshots\SnapshotService;
use App\Filament\Resources\Reports\Pages\ReportDesigner;
use App\Filament\Resources\ScheduledTasks\Pages\CreateScheduledTask;
use App\Filament\Resources\TableConnector\Pages\EditConnectedTable;
use App\Filament\Resources\TableConnector\RelationManagers\SnapshotsRelationManager;
use App\Models\CustomTable;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\SnapshotSchedules;
use App\Tasks\TaskRunner;
use App\Tasks\Types\SnapshotConnectionRefreshTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Zeitpläne für Datenstände: je Tabelle und je Verbindung, Anzeige im Table-Connector, Schwelle im Editor. */
class SnapshotSchedulesTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    protected array $connectionsToTransact = [null, 'app_data'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $this->connectResearchDemoTables($this->researchConnection());
        $this->admin = $this->superAdmin();
    }

    private function table(string $name): CustomTable
    {
        return CustomTable::query()->where('name', $name)->sole();
    }

    private function connectionTask(bool $onlyWithSnapshot = true): ScheduledTask
    {
        return ScheduledTask::create([
            'type' => SnapshotConnectionRefreshTask::KEY,
            'parameters' => ['connection_id' => $this->table('fdb_personen')->database_connection_id, 'only_with_snapshot' => $onlyWithSnapshot],
            'frequency' => 'every_hours', 'interval_hours' => 6, 'at_time' => '00:00',
        ]);
    }

    public function test_connection_task_refreshes_tables_with_a_snapshot_or_all(): void
    {
        $people = $this->table('fdb_personen');
        app(SnapshotService::class)->refresh($people, $this->admin);
        $task = $this->connectionTask();
        $this->assertStringEndsWith('· Tabellen mit Datenstand', $task->description());

        $run = app(TaskRunner::class)->run($task, TaskRun::SCHEDULE);
        $this->assertSame(ScheduledTask::SUCCESS, $run->status, (string) $run->message);
        $this->assertSame('1 von 1 Tabellen aktualisiert (20 Zeilen).', $run->message);
        $this->assertSame(2, $people->snapshots()->count());

        // Alle angebundenen Tabellen der Verbindung; eine gerade laufende wird übersprungen.
        $all = $this->connectionTask(onlyWithSnapshot: false);
        $total = CustomTable::query()->external()->where('database_connection_id', $people->database_connection_id)->count();
        $lock = Cache::lock('snapshots:table:'.$people->id, 60);
        $lock->get();
        $run = app(TaskRunner::class)->run($all, TaskRun::MANUAL);
        $lock->release();

        $this->assertSame(ScheduledTask::SUCCESS, $run->status, (string) $run->message);
        $this->assertStringStartsWith(($total - 1).' von '.$total.' Tabellen aktualisiert', $run->message);
        $this->assertStringContainsString('Übersprungen (wird gerade aktualisiert): '.$people->label, $run->message);
        $this->assertSame($total - 1, CustomTable::query()->external()->whereHas('snapshots')->count() - 1);
    }

    public function test_schedules_per_table_and_connection_and_stale_threshold(): void
    {
        $people = $this->table('fdb_personen');
        $orgs = $this->table('fdb_organisationen');
        $schedules = app(SnapshotSchedules::class);
        $this->assertSame(24, $schedules->staleHours($people), 'Ohne Zeitplan: reports.snapshot_stale_hours.');

        $daily = ScheduledTask::create(['type' => 'snapshot_refresh', 'parameters' => ['table_id' => (string) $people->id], 'frequency' => 'daily', 'at_time' => '06:00']);
        $this->assertSame([$daily->id], $schedules->for($people)->pluck('id')->all());
        $this->assertSame(48, $schedules->staleHours($people));
        $this->assertSame([], $schedules->for($orgs)->pluck('id')->all());

        // Verbindungs-Aufgabe „nur mit Datenstand“ gilt erst, wenn die Tabelle einen hat; alle 6 Stunden → 12.
        $connection = $this->connectionTask();
        $this->assertSame([], $schedules->for($orgs)->pluck('id')->all());
        app(SnapshotService::class)->refresh($orgs, $this->admin);
        $this->assertSame([$connection->id], $schedules->for($orgs->fresh())->pluck('id')->all());
        $this->assertSame(12, $schedules->staleHours($orgs->fresh()));

        // Inaktiv zählt nicht.
        $daily->update(['is_active' => false]);
        $this->assertSame(24, $schedules->staleHours($people));
    }

    public function test_designer_respects_the_schedule(): void
    {
        $this->actingAs($this->admin);
        $people = $this->table('fdb_personen');
        $old = app(SnapshotService::class)->refresh($people, $this->admin);
        $old->update(['finished_at' => now()->subHours(30)]);
        ScheduledTask::create(['type' => 'snapshot_refresh', 'parameters' => ['table_id' => $people->id], 'frequency' => 'daily', 'at_time' => '06:00']);

        // 30 Stunden bei täglichem Zeitplan: noch nicht veraltet (Grenze 48 Stunden).
        Livewire::test(ReportDesigner::class)->call('setBase', $people->id)->assertActionNotMounted('refreshSnapshots')->assertSet('state.base', $people->id);

        $old->update(['finished_at' => now()->subHours(50)]);
        Livewire::test(ReportDesigner::class)
            ->call('setBase', $people->id)
            ->assertActionMounted('refreshSnapshots')
            ->tap(function ($designer): void {
                $text = (string) $designer->instance()->refreshSnapshotsAction()->getModalDescription();
                $this->assertStringContainsString('älter als erwartet', $text);
                $this->assertStringContainsString('(Zeitplan: täglich um 06:00)', $text);
            });
    }

    public function test_connector_shows_schedules_and_offers_setting_one_up(): void
    {
        $this->actingAs($this->admin);
        $people = $this->table('fdb_personen');
        app(TaskRunner::class)->run(ScheduledTask::create(['type' => 'snapshot_refresh', 'parameters' => ['table_id' => $people->id], 'frequency' => 'weekdays', 'at_time' => '06:30']), TaskRun::SCHEDULE);

        Livewire::test(SnapshotsRelationManager::class, ['ownerRecord' => $people, 'pageClass' => EditConnectedTable::class])
            ->assertSeeHtml('data-snapshot-schedule')
            ->assertSee('werktags um 06:30')
            ->assertSee('automatisch')
            ->assertTableActionVisible('setupSchedule');

        $orgs = $this->table('fdb_organisationen');
        Livewire::test(SnapshotsRelationManager::class, ['ownerRecord' => $orgs, 'pageClass' => EditConnectedTable::class])
            ->assertSee('Kein Zeitplan – Datenstände nur von Hand.');

        // Vorbelegt aus dem Table-Connector.
        Livewire::withQueryParams(['type' => 'snapshot_refresh', 'table' => $orgs->id])
            ->test(CreateScheduledTask::class)
            ->assertFormSet(['type' => 'snapshot_refresh', 'frequency' => 'daily', 'at_time' => '06:00', 'parameters.table_id' => $orgs->id])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(2, ScheduledTask::query()->where('type', 'snapshot_refresh')->count());

        // Ohne tasks.create keine Abkürzung.
        $this->actingAs($this->userWithPermissions(['table_connector.view', 'table_connector.update']));
        Livewire::test(SnapshotsRelationManager::class, ['ownerRecord' => $orgs, 'pageClass' => EditConnectedTable::class])
            ->assertTableActionHidden('setupSchedule');
    }
}
