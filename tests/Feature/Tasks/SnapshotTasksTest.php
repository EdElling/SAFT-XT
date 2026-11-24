<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\DynamicData\DynamicDataNamespace;
use App\ExternalData\Snapshots\SnapshotService;
use App\Filament\Resources\ScheduledTasks\Pages\CreateScheduledTask;
use App\Models\CustomTable;
use App\Models\DataSnapshot;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\WorkItem;
use App\Tasks\TaskRunner;
use App\Work\WorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Geplante Aufgaben rund um Datenstände angebundener Tabellen. */
class SnapshotTasksTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    protected array $connectionsToTransact = [null, 'app_data'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $this->connectResearchDemoTables($this->researchConnection());
    }

    private function people(): CustomTable
    {
        return CustomTable::query()->where('name', 'fdb_personen')->sole();
    }

    private function refreshTask(): ScheduledTask
    {
        return ScheduledTask::create(['type' => 'snapshot_refresh', 'parameters' => ['table_id' => $this->people()->id], 'frequency' => 'daily', 'at_time' => '06:00']);
    }

    public function test_refresh_task_creates_an_automatic_snapshot(): void
    {
        $task = $this->refreshTask();
        $this->assertSame('fdb_personen ('.$this->people()->databaseConnection->name.')', str_replace((string) $this->people()->label, 'fdb_personen', $task->description()));

        $run = app(TaskRunner::class)->run($task, TaskRun::SCHEDULE);

        $this->assertSame(ScheduledTask::SUCCESS, $run->status, (string) $run->message);
        $this->assertSame('Neuer Datenstand mit 20 Zeilen.', $run->message);
        $snapshot = $this->people()->latestSnapshot();
        $this->assertSame(20, $snapshot->row_count);
        $this->assertNull($snapshot->created_by, 'Automatisch, ohne Person.');
        $this->assertNull(Activity::query()->where('event', 'snapshot_created')->sole()->causer_id);
        $this->assertSame(['snapshot_id' => $snapshot->id, 'rows' => 20, 'work' => $snapshot->work->uuid], $run->result, 'Der Lauf nennt die Arbeit.');
        $this->assertSame([WorkItem::DONE, 20, 20], [$snapshot->work->status, $snapshot->work->done, $snapshot->work->total]);
    }

    public function test_refresh_of_the_same_table_never_runs_twice(): void
    {
        $busy = $this->busySnapshot($this->people());

        $run = app(TaskRunner::class)->run($this->refreshTask(), TaskRun::SCHEDULE);
        $this->assertSame(ScheduledTask::SKIPPED, $run->status);
        $this->assertSame('Diese Tabelle wird gerade aktualisiert. Bitte später noch einmal versuchen.', $run->message);

        // Abbruch der laufenden Arbeit: der halbe Stand ist fehlgeschlagen, die Tabelle wieder frei.
        app(WorkService::class)->cancel($busy);
        $this->assertSame([DataSnapshot::FAILED, 'Abgebrochen.'], [DataSnapshot::query()->sole()->status, DataSnapshot::query()->sole()->message]);
        $this->assertSame(ScheduledTask::SUCCESS, app(TaskRunner::class)->run($this->refreshTask(), TaskRun::SCHEDULE)->status);

        // Fehlt die Tabelle, schlägt die Aufgabe mit einer klaren Meldung fehl.
        $gone = ScheduledTask::create(['type' => 'snapshot_refresh', 'parameters' => ['table_id' => 999999], 'frequency' => 'daily']);
        $this->assertSame('Die Tabelle gibt es nicht mehr.', app(TaskRunner::class)->run($gone, TaskRun::MANUAL)->message);
    }

    public function test_prune_enforces_retention_without_a_new_snapshot_and_cleans_up_aborted_runs(): void
    {
        $people = $this->people();
        $service = app(SnapshotService::class);
        $old = $service->refresh($people, null);
        $newest = $service->refresh($people, null);
        $people->update(['snapshot_max_days' => 7]);
        $old->update(['finished_at' => now()->subDays(10)]);

        // Verwaister Lauf von vor 3 Stunden (Arbeit weg) mit halb kopierten Zeilen.
        $aborted = DataSnapshot::create(['custom_table_id' => $people->id, 'status' => DataSnapshot::RUNNING, 'started_at' => now()->subHours(3)]);
        $copy = app(DynamicDataNamespace::class)->connection()->table($service->qualified($people));
        $row = (array) $copy->where(SnapshotService::SNAPSHOT_COLUMN, $newest->id)->first();
        unset($row[SnapshotService::ROW_COLUMN]);
        $copy->insert([...$row, SnapshotService::SNAPSHOT_COLUMN => $aborted->id]);

        $prune = ScheduledTask::query()->where('type', 'snapshot_prune')->sole();
        $run = app(TaskRunner::class)->run($prune, TaskRun::SCHEDULE);

        $this->assertSame('1 Stände nach Frist gelöscht, 1 abgebrochene aufgeräumt.', $run->message);
        $this->assertNull($old->fresh());
        $this->assertNotNull($newest->fresh(), 'Der neueste Stand bleibt immer.');
        $this->assertSame(0, $copy->newQuery()->from($service->qualified($people))->where(SnapshotService::SNAPSHOT_COLUMN, $aborted->id)->count());
    }

    public function test_create_form_offers_connected_tables(): void
    {
        $this->actingAs($this->userWithPermissions(['tasks.view', 'tasks.create']));

        Livewire::test(CreateScheduledTask::class)
            ->fillForm(['type' => 'snapshot_refresh'])
            ->assertFormFieldExists('parameters.table_id')
            ->fillForm(['parameters.table_id' => $this->people()->id, 'frequency' => 'every_hours', 'interval_hours' => 6, 'at_time' => '00:30'])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = ScheduledTask::query()->where('type', 'snapshot_refresh')->sole();
        $this->assertSame(['table_id' => $this->people()->id], $task->parameterValues());
        $this->assertSame('alle 6 Stunden ab 00:30', $task->schedule()->describe());

        // Ohne Tabelle: Pflichtfeld.
        Livewire::test(CreateScheduledTask::class)
            ->fillForm(['type' => 'snapshot_refresh', 'frequency' => 'daily'])
            ->call('create')
            ->assertHasFormErrors(['parameters.table_id' => 'required']);
    }
}
