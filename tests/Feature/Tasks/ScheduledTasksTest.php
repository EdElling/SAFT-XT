<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ScheduledTasks\Pages\CreateScheduledTask;
use App\Filament\Resources\ScheduledTasks\Pages\EditScheduledTask;
use App\Filament\Resources\ScheduledTasks\Pages\ListScheduledTasks;
use App\Filament\Resources\ScheduledTasks\RelationManagers\TaskRunsRelationManager;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRegistry;
use App\Tasks\TaskResult;
use App\Tasks\TaskRunner;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;
use App\Tasks\Types\BackupPruneTask;
use App\Tasks\Types\DatabaseBackupTask;
use App\Tasks\Types\DemoResetTask;
use App\Tasks\Types\SnapshotPruneTask;
use App\Tasks\Types\SnapshotRefreshTask;
use App\Tasks\Types\TaskRunsPruneTask;
use Database\Seeders\SystemTasksSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Geplante Aufgaben: Runner, Benachrichtigungen, System-Aufgaben, Verwaltung und Befehle. */
class ScheduledTasksTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        config(['tasks.types' => [SnapshotRefreshTask::class, SnapshotPruneTask::class, TaskRunsPruneTask::class, DatabaseBackupTask::class, BackupPruneTask::class, DemoResetTask::class, FakeTask::class]]);
        app()->forgetInstance(TaskRegistry::class);
        FakeTask::$outcome = 'success';
        $this->travelTo(now()->setTimezone('Europe/Zurich')->setDate(2026, 11, 14)->setTime(5, 0));
    }

    private function fakeTask(array $attributes = []): ScheduledTask
    {
        return ScheduledTask::create([...['type' => 'fake', 'frequency' => 'daily', 'at_time' => '06:00'], ...$attributes]);
    }

    private function runner(): TaskRunner
    {
        return app(TaskRunner::class);
    }

    public function test_system_tasks_come_with_the_migration_and_get_their_first_date_without_running(): void
    {
        $system = ScheduledTask::query()->where('is_system', true)->orderBy('id')->get();
        $this->assertSame(['snapshot_prune', 'task_runs_prune', 'db_backup', 'backup_prune', 'demo_reset'], $system->pluck('type')->all());
        $this->assertSame([90], [$system[1]->parameterValues()['days']]);
        $this->assertSame([true, true, true, true, false], $system->pluck('is_active')->all(), 'Demo zurücksetzen ist aus.');

        $this->runner()->runDue();

        $this->assertSame(0, TaskRun::query()->count(), 'Erster Aufruf setzt nur Termine.');
        $this->assertSame('2026-11-15 03:15', $system[0]->fresh()->next_run_at->format('Y-m-d H:i'), 'Heute 03:15 ist vorbei.');
        $this->assertTrue($this->runner()->schedulerAlive());
    }

    public function test_due_tasks_run_and_get_their_next_date(): void
    {
        $task = $this->fakeTask();
        $this->assertSame('2026-11-14 06:00', $task->next_run_at->format('Y-m-d H:i'));

        $this->assertSame([], $this->runner()->runDue(), 'Noch nicht fällig.');

        // Server war aus: um 09:00 wird der verpasste Termin einmal nachgeholt, der nächste ist morgen.
        $this->travelTo(now()->setTime(9, 0));
        $runs = $this->runner()->runDue();
        $fake = collect($runs)->filter(fn (TaskRun $run): bool => $run->scheduled_task_id === $task->id)->values();

        $this->assertCount(1, $fake);
        $this->assertSame(ScheduledTask::SUCCESS, $fake[0]->status);
        $this->assertSame(TaskRun::SCHEDULE, $fake[0]->trigger);
        $this->assertSame('Fertig', $fake[0]->message);
        $this->assertSame(['n' => 1], $fake[0]->result);

        $task->refresh();
        $this->assertSame(ScheduledTask::SUCCESS, $task->last_status);
        $this->assertSame('2026-11-15 06:00', $task->next_run_at->format('Y-m-d H:i'));

        // Inaktiv: kein Termin, läuft nicht.
        $task->update(['is_active' => false]);
        $this->assertNull($task->fresh()->next_run_at);
    }

    public function test_failure_notifies_super_admins_once_and_again_on_recovery(): void
    {
        // Die englischsprachige Person zuerst anlegen: Sie wird vor der ohne eigene Sprache benachrichtigt (B-059).
        $other = $this->superAdmin();
        $other->update(['locale' => 'en']);
        $admin = $this->superAdmin();
        $this->userWithPermissions(['tasks.view']);
        $task = $this->fakeTask(['name' => 'Testlauf']);

        FakeTask::$outcome = 'fail';
        $run = $this->runner()->run($task, TaskRun::MANUAL, $admin);
        $this->assertSame(ScheduledTask::FAILED, $run->status);
        $this->assertSame('Quelle nicht erreichbar', $run->message);
        $this->runner()->run($task->fresh(), TaskRun::MANUAL);
        $this->assertSame(2, $task->fresh()->consecutive_failures);

        $this->assertSame(1, $admin->notifications()->count(), 'Nur beim ersten Fehlschlag.');
        $this->assertSame('Geplante Aufgabe fehlgeschlagen: Testlauf', $admin->notifications()->first()->data['title']);
        $this->assertSame('Scheduled task failed: Testlauf', $other->notifications()->first()->data['title'], 'In der Sprache der Person.');
        $this->assertSame(2, DatabaseNotification::query()->count(), 'Nur Super-Admins.');

        FakeTask::$outcome = 'success';
        $this->runner()->run($task->fresh(), TaskRun::MANUAL);
        $this->assertSame(0, $task->fresh()->consecutive_failures);
        $this->assertEqualsCanonicalizing(
            ['Geplante Aufgabe fehlgeschlagen: Testlauf', 'Geplante Aufgabe läuft wieder: Testlauf'],
            $admin->notifications()->get()->map(fn ($n): string => $n->data['title'])->all(),
        );

        // Ein unerwarteter Fehler zeigt keine Interna, aber eine Referenz; die Details stehen am Lauf.
        FakeTask::$outcome = 'crash';
        Log::spy();
        $run = $this->runner()->run($task->fresh(), TaskRun::MANUAL);
        $this->assertMatchesRegularExpression('/^Die Aufgabe ist fehlgeschlagen \(Referenz ([A-Z0-9]{8})\)\./', (string) $run->message);
        $this->assertStringNotContainsString('SQLSTATE', (string) $run->message);
        $detail = $run->fresh()->error_detail;
        $this->assertStringContainsString('Referenz '.$detail['reference'], (string) $run->message);
        $this->assertSame([RuntimeException::class, 'SQLSTATE geheim'], [$detail['class'], $detail['message']]);
        $this->assertStringStartsWith('tests/Feature/Tasks/ScheduledTasksTest.php:', $detail['location'], 'Pfad relativ zum Programmordner.');
        $this->assertNotEmpty($detail['trace']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['error_reference'] === $detail['reference']);
    }

    public function test_error_details_of_a_run_only_with_permission(): void
    {
        $task = $this->fakeTask();
        FakeTask::$outcome = 'crash';
        $failed = $this->runner()->run($task, TaskRun::MANUAL);
        FakeTask::$outcome = 'success';
        $ok = $this->runner()->run($task->fresh(), TaskRun::MANUAL);
        $this->assertNull($ok->fresh()->error_detail);

        $this->actingAs($this->userWithPermissions(['tasks.view', 'tasks.update']));
        Livewire::test(TaskRunsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => EditScheduledTask::class])
            ->assertTableActionHidden('errorDetails', $failed);

        $this->actingAs($this->userWithPermissions(['tasks.view', 'tasks.details']));
        Livewire::test(TaskRunsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => EditScheduledTask::class])
            ->assertTableActionVisible('errorDetails', $failed)
            ->assertTableActionHidden('errorDetails', $ok)
            ->mountTableAction('errorDetails', $failed)
            ->assertHasNoErrors();
        $html = view('filament.tasks.error-detail', ['detail' => $failed->fresh()->error_detail])->render();
        $this->assertStringContainsString('SQLSTATE geheim', $html);
        $this->assertStringContainsString('data-error-reference="'.$failed->fresh()->error_detail['reference'].'"', $html);

        $this->actingAs($this->superAdmin());
        Livewire::test(TaskRunsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => EditScheduledTask::class])
            ->assertTableActionVisible('errorDetails', $failed);
    }

    public function test_a_task_never_runs_twice_and_skips_are_no_failures(): void
    {
        $task = $this->fakeTask();
        $lock = Cache::lock('tasks:run:'.$task->id, 60);
        $this->assertTrue($lock->get());

        $run = $this->runner()->run($task, TaskRun::MANUAL);
        $this->assertSame(ScheduledTask::SKIPPED, $run->status);
        $this->assertSame('Diese Aufgabe läuft bereits.', $run->message);
        $lock->release();

        FakeTask::$outcome = 'skip';
        $this->assertSame(ScheduledTask::SKIPPED, $this->runner()->run($task->fresh(), TaskRun::MANUAL)->status);
        $this->assertSame(0, $task->fresh()->consecutive_failures);

        // Von Hand ausgeführt: Termin bleibt.
        $this->assertSame('2026-11-14 06:00', $task->fresh()->next_run_at->format('Y-m-d H:i'));
    }

    public function test_hanging_runs_fail_and_old_runs_are_pruned(): void
    {
        $task = $this->fakeTask();
        $hanging = $task->runs()->create(['trigger' => TaskRun::SCHEDULE, 'status' => TaskRun::RUNNING, 'started_at' => now()->subHours(7)]);
        $old = $task->runs()->create(['trigger' => TaskRun::SCHEDULE, 'status' => ScheduledTask::SUCCESS, 'started_at' => now()->subDays(91)]);
        $recent = $task->runs()->create(['trigger' => TaskRun::SCHEDULE, 'status' => ScheduledTask::SUCCESS, 'started_at' => now()->subDays(89)]);

        $this->assertSame(1, $this->runner()->failHanging());
        $this->assertSame(ScheduledTask::FAILED, $hanging->fresh()->status);

        $prune = ScheduledTask::query()->where('type', 'task_runs_prune')->sole();
        $run = $this->runner()->run($prune, TaskRun::MANUAL);
        $this->assertSame('1 Läufe gelöscht.', $run->message);
        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());

        // Frist anpassbar.
        $prune->update(['parameters' => ['days' => 30]]);
        $this->runner()->run($prune->fresh(), TaskRun::MANUAL);
        $this->assertNull($recent->fresh());
    }

    public function test_runtime_values_stay_out_of_the_activity_log(): void
    {
        $task = $this->fakeTask(['name' => 'Protokoll']);
        $before = Activity::query()->where('subject_type', $task->getMorphClass())->count();

        $this->runner()->run($task, TaskRun::MANUAL);

        $this->assertSame($before, Activity::query()->where('subject_type', $task->getMorphClass())->count());
        $task->update(['at_time' => '07:00']);
        $this->assertSame($before + 1, Activity::query()->where('subject_type', $task->getMorphClass())->count());
    }

    public function test_admin_ui_with_permissions(): void
    {
        $this->actingAs($this->userWithPermissions());
        $this->get(route('filament.admin.resources.scheduled-tasks.index'))->assertForbidden();

        $user = $this->userWithPermissions(['tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete']);
        $this->actingAs($user);

        Livewire::test(ListScheduledTasks::class)
            ->assertOk()
            ->assertSee('Datenstände bereinigen')
            ->assertSee('täglich um 03:15')
            ->assertSee('Der Scheduler lief seit über 15 Minuten nicht');

        // Neue Aufgabe: stündlich zur Minute 20.
        Livewire::test(CreateScheduledTask::class)
            ->fillForm(['type' => 'fake', 'name' => 'Stündlich', 'frequency' => 'hourly', 'minute' => 20])
            ->call('create')
            ->assertHasNoFormErrors();
        $task = ScheduledTask::query()->where('name', 'Stündlich')->sole();
        $this->assertSame('00:20', $task->at_time);
        $this->assertSame($user->id, $task->created_by);
        $this->assertFalse($task->is_system);
        $this->assertSame('2026-11-14 05:20', $task->next_run_at->format('Y-m-d H:i'));

        // Bearbeiten: wöchentlich, Dienstag 18:00; die Art bleibt.
        Livewire::test(EditScheduledTask::class, ['record' => $task->id])
            ->assertFormSet(['minute' => 20])
            ->fillForm(['frequency' => 'weekly', 'at_time' => '18:00', 'weekday' => 2])
            ->call('save')
            ->assertHasNoFormErrors();
        $task->refresh();
        $this->assertSame(['fake', 'weekly', '18:00', 2, null], [$task->type, $task->frequency, $task->at_time, $task->weekday, $task->interval_hours]);
        $this->assertSame('2026-11-17 18:00', $task->next_run_at->format('Y-m-d H:i'));

        // Ungültige Uhrzeit.
        Livewire::test(EditScheduledTask::class, ['record' => $task->id])
            ->fillForm(['at_time' => '25:00'])
            ->call('save')
            ->assertHasFormErrors(['at_time']);

        // Jetzt ausführen.
        Livewire::test(ListScheduledTasks::class)->callAction(TestAction::make('runNow')->table($task));
        $this->assertSame(TaskRun::MANUAL, $task->runs()->sole()->trigger);
        $this->assertSame($user->id, $task->runs()->sole()->triggered_by);

        // System-Aufgaben lassen sich nicht löschen, wohl aber anpassen; System-Arten lassen sich nicht neu anlegen.
        $system = ScheduledTask::query()->where('type', 'task_runs_prune')->sole();
        Livewire::test(ListScheduledTasks::class)->assertActionHidden(TestAction::make('delete')->table($system));
        Livewire::test(EditScheduledTask::class, ['record' => $system->id])
            ->assertFormSet(['parameters.days' => 90])
            ->fillForm(['parameters.days' => 120])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(120, (int) $system->fresh()->parameterValues()['days']);
        $this->assertArrayNotHasKey('task_runs_prune', app(TaskRegistry::class)->creatableOptions());
    }

    public function test_seeder_restores_missing_system_tasks_without_overwriting_changes(): void
    {
        $prune = ScheduledTask::query()->where('type', 'task_runs_prune')->sole();
        $prune->update(['at_time' => '04:00', 'parameters' => ['days' => 30]]);
        ScheduledTask::query()->where('type', 'snapshot_prune')->delete();

        $this->seed(SystemTasksSeeder::class);
        $this->seed(SystemTasksSeeder::class);

        $this->assertSame(['backup_prune', 'db_backup', 'demo_reset', 'snapshot_prune', 'task_runs_prune'], ScheduledTask::query()->where('is_system', true)->orderBy('type')->pluck('type')->all());
        $this->assertSame(['04:00', 30], [$prune->fresh()->at_time, $prune->fresh()->parameterValues()['days']]);
    }

    public function test_commands_and_scheduler_entry(): void
    {
        $task = $this->fakeTask(['name' => 'Konsole']);

        $this->artisan('tasks:list')->expectsOutputToContain('Konsole')->assertSuccessful();
        $this->artisan('tasks:run', ['task' => $task->id])->expectsOutputToContain('Erfolgreich')->assertSuccessful();
        $this->assertSame(TaskRun::CONSOLE, $task->runs()->sole()->trigger);
        $this->artisan('tasks:run', ['task' => 9999])->assertFailed();

        FakeTask::$outcome = 'fail';
        $this->travelTo(now()->setTime(6, 1));
        $this->artisan('tasks:run-due')->assertFailed();

        $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);
        $this->assertTrue($events->contains(fn (string $command): bool => str_contains($command, 'tasks:run-due')));
    }
}

/** Test-Aufgabe mit vorgegebenem Ergebnis. */
final class FakeTask implements TaskType
{
    public static string $outcome = 'success';

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Test';
    }

    public function creatable(): bool
    {
        return true;
    }

    public function parameterFields(): array
    {
        return [];
    }

    public function describe(array $parameters): string
    {
        return 'Testaufgabe';
    }

    public function run(array $parameters): TaskResult
    {
        return match (self::$outcome) {
            'fail' => throw new BusinessRuleException('Quelle nicht erreichbar'),
            'crash' => throw new RuntimeException('SQLSTATE geheim'),
            'skip' => throw new TaskSkipped('läuft schon'),
            default => new TaskResult('Fertig', ['n' => 1]),
        };
    }
}
