<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Auth\RoleSimulation;
use App\DynamicData\DynamicDataNamespace;
use App\Models\Activity;
use App\Models\DynamicRecord;
use App\Models\Role;
use App\Models\WorkItem;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\DynamicData\DynamicDataTestCase;
use Tests\Fixtures\Work\CountingWork;
use Tests\Fixtures\Work\WritingWork;

/** Arbeiten (Fundament 2, F3a): Runner, Sperre, Wiederaufnahme, Abbrechen, Fehler, Kontext, work:run. */
class WorkRunnerTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        config(['work.types' => [...(array) config('work.types'), CountingWork::class, WritingWork::class]]);
        CountingWork::$steps = 0;
        Storage::fake('local');
    }

    private function runner(): WorkRunner
    {
        return app(WorkRunner::class);
    }

    /** @param  array<string, mixed>  $parameters */
    private function counting(array $parameters): WorkItem
    {
        return app(WorkService::class)->start(CountingWork::KEY, ['total' => 10, 'per_step' => 3, ...$parameters]);
    }

    public function test_work_advances_in_steps_and_resumes_from_the_cursor(): void
    {
        $item = $this->counting([]);
        $this->assertSame(WorkItem::QUEUED, $item->status);

        // Budget 0: genau ein Schritt je Aufruf – wie ein Treiber, dessen Zeit abgelaufen ist.
        $item = $this->runner()->advance($item, 0);
        $this->assertSame([WorkItem::RUNNING, 3, 10, ['position' => 3], 30], [$item->status, $item->done, $item->total, $item->state, $item->percent()]);

        // Neuer Prozess: nur der gespeicherte Stand zählt.
        $item = $this->runner()->advance(WorkItem::query()->findOrFail($item->id), 0);
        $this->assertSame(['position' => 6], $item->state);

        $item = $this->runner()->advance($item, 30);
        $this->assertSame([WorkItem::DONE, 12, 'Gezählt bis 12', 100, null], [$item->status, $item->done, $item->message, $item->percent(), $item->state]);
        $this->assertSame(4, CountingWork::$steps);
        $this->assertNotNull($item->finished_at);
        $this->assertTrue($item->expires_at->isAfter(now()->addHours(23)));

        // Ins Protokoll kommen Start und Statuswechsel, nicht der Fortschritt.
        $this->assertSame(3, Activity::query()->where('subject_type', $item->getMorphClass())->where('subject_id', $item->id)->count());
    }

    public function test_a_locked_work_is_left_alone(): void
    {
        $item = $this->counting([]);
        $lock = Cache::lock(WorkRunner::lockKey($item), 60);
        $this->assertTrue($lock->get());

        $item = $this->runner()->advance($item, 30);
        $this->assertSame([WorkItem::QUEUED, 0], [$item->status, CountingWork::$steps]);

        $lock->release();
        $this->assertSame(WorkItem::DONE, $this->runner()->advance($item, 30)->status);
    }

    public function test_steps_without_completion_end_the_work(): void
    {
        $item = $this->counting([]);
        $this->runner()->advance($item, 0);

        // Zwei abgebrochene Prozesse: der nächste Schritt läuft und setzt den Zähler zurück.
        $item->forceFill(['stalls' => 2])->save();
        $item = $this->runner()->advance($item, 0);
        $this->assertSame([WorkItem::RUNNING, 0, 6], [$item->status, $item->stalls, $item->done]);

        // Drei: Ende, ohne weiteren Versuch.
        $item->forceFill(['stalls' => 3])->save();
        $steps = CountingWork::$steps;
        $item = $this->runner()->advance($item, 30);
        $this->assertSame([WorkItem::FAILED, $steps], [$item->status, CountingWork::$steps]);
        $this->assertStringContainsString('ohne Abschluss', (string) $item->message);
    }

    public function test_cancelling_ends_waiting_work_at_once_and_running_work_between_steps(): void
    {
        $waiting = app(WorkService::class)->cancel($this->counting([]));
        $this->assertSame([WorkItem::CANCELLED, 0], [$waiting->status, CountingWork::$steps]);

        // Ein Treiber arbeitet daran (Sperre): nur die Marke, der Treiber beendet die Arbeit.
        $running = $this->runner()->advance($this->counting(['file' => true, 'total' => 100]), 0);
        Storage::disk('local')->put($running->directory().'/teil.txt', 'x');
        $lock = Cache::lock(WorkRunner::lockKey($running), 60);
        $lock->get();
        $running = app(WorkService::class)->cancel($running);
        $this->assertSame(WorkItem::RUNNING, $running->status);
        $this->assertNotNull($running->cancel_requested_at);
        $lock->release();

        $running = $this->runner()->advance($running, 30);
        $this->assertSame([WorkItem::CANCELLED, 'Abgebrochen.'], [$running->status, $running->message]);
        Storage::disk('local')->assertMissing($running->directory());

        // Ohne Treiber endet auch eine laufende Arbeit sofort.
        $idle = $this->runner()->advance($this->counting(['total' => 100]), 0);
        $this->assertSame(WorkItem::CANCELLED, app(WorkService::class)->cancel($idle)->status);
    }

    public function test_failures_keep_a_reference_and_business_messages(): void
    {
        $item = $this->runner()->advance($this->counting(['fail_at' => 6]), 30);
        $this->assertSame([WorkItem::FAILED, 3], [$item->status, $item->done]);
        $this->assertMatchesRegularExpression('/Referenz [A-Z0-9]{8}/', (string) $item->message);
        $this->assertSame('Technischer Fehler bei 6', $item->error_detail['message']);
        $this->assertNull($item->expires_at);

        $item = $this->runner()->advance($this->counting(['fail_at' => 3, 'business' => true]), 30);
        $this->assertSame([WorkItem::FAILED, 'Fachlicher Fehler bei 3'], [$item->status, $item->message]);
    }

    public function test_a_writing_step_and_its_progress_are_one_transaction(): void
    {
        $this->projects();
        $records = fn (): int => DynamicRecord::forTableName('projekte')->newQuery()->count();

        $item = $this->runner()->advance(app(WorkService::class)->start(WritingWork::KEY, ['table' => 'projekte', 'fail_in_step' => 2]), 30);
        $this->assertSame([WorkItem::FAILED, 1, 1], [$item->status, $item->done, $records()]);

        $item = $this->runner()->advance(app(WorkService::class)->start(WritingWork::KEY, ['table' => 'projekte']), 30);
        $this->assertSame([WorkItem::DONE, 2, 3], [$item->status, $item->done, $records()]);
    }

    public function test_steps_run_as_the_starting_person_in_the_app_of_the_work(): void
    {
        $admin = $this->superAdmin();
        $app = self::ensureTestApp();
        $item = app(WorkService::class)->start(CountingWork::KEY, ['total' => 1], $admin, $app->id);

        $item = $this->runner()->advance($item, 30);
        $this->assertSame(['user' => $admin->id, 'can_view_reports' => true, 'app' => $app->id], array_intersect_key($item->result, array_flip(['user', 'can_view_reports', 'app'])));
        // Danach ist niemand angemeldet, wie vorher.
        $this->assertNull(Auth::user());

        // Unter „Ansicht als Rolle“ gestartet: die simulierte Rolle gilt auch im Hintergrund.
        $role = Role::findOrCreate('nur-lesen', 'web');
        $item = app(WorkService::class)->start(CountingWork::KEY, ['total' => 1], $admin);
        $item->forceFill(['simulated_roles' => [$role->id]])->save();
        $this->assertFalse($this->runner()->advance($item, 30)->result['can_view_reports']);
    }

    public function test_start_remembers_the_simulated_roles_of_the_person(): void
    {
        $admin = $this->superAdmin();
        $role = Role::findOrCreate('nur-lesen', 'web');
        $this->actingAs($admin)->withSession([])->get('/');
        session()->put('saft.simulated_roles', [$role->id]);
        app(RoleSimulation::class)->apply($admin);

        $item = app(WorkService::class)->start(CountingWork::KEY, ['total' => 1], $admin);
        $this->assertSame([$role->id], $item->simulated_roles);
    }

    public function test_the_scheduler_takes_over_abandoned_work_only(): void
    {
        $fresh = $this->runner()->advance($this->counting(['total' => 100]), 0);
        $abandoned = $this->runner()->advance($this->counting(['total' => 6]), 0);
        $abandoned->forceFill(['heartbeat_at' => now()->subMinute()])->save();
        $justStarted = $this->counting([]);
        $cancelled = $this->runner()->advance($this->counting(['total' => 100]), 0);
        $cancelled->forceFill(['cancel_requested_at' => now()])->save();

        $this->artisan('work:run', ['--budget' => 20])->assertSuccessful();

        $this->assertSame(WorkItem::RUNNING, $fresh->fresh()->status);
        $this->assertSame(WorkItem::DONE, $abandoned->fresh()->status);
        $this->assertSame(WorkItem::QUEUED, $justStarted->fresh()->status);
        $this->assertSame(WorkItem::CANCELLED, $cancelled->fresh()->status);
        $this->assertNotNull(Cache::get(WorkRunner::SCHEDULER_HEARTBEAT));
    }

    public function test_recompute_runs_as_work(): void
    {
        $lines = $this->service()->createTable(['name' => 'zeilen', 'label' => 'Zeilen'], [
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'doppelt', 'label' => 'Doppelt', 'type' => 'computed', 'options' => ['result' => 'integer', 'formula' => 'menge * 2']],
        ]);
        $record = DynamicRecord::forTable($lines->fresh('fields'))->create(['menge' => 2]);
        $namespace = app(DynamicDataNamespace::class);
        $namespace->connection()->table($namespace->qualify('zeilen'))->update(['menge' => 7]);

        $this->artisan('computed:recompute', ['table' => ['zeilen']])->assertSuccessful();
        $this->assertSame(14, $record->fresh()->doppelt);

        $item = WorkItem::query()->where('type', 'recompute')->latest('id')->firstOrFail();
        $this->assertSame([WorkItem::DONE, 1, 1, ['zeilen' => 1]], [$item->status, $item->total, $item->done, $item->result['changed']]);
        $this->assertSame('zeilen', $item->type()->describe($item->parameterValues()));

        // Im Hintergrund: nur gestartet, work:run rechnet.
        $namespace->connection()->table($namespace->qualify('zeilen'))->update(['menge' => 1]);
        $this->artisan('computed:recompute', ['--background' => true])->assertSuccessful();
        $this->assertSame(14, $record->fresh()->doppelt);
        $this->travel(1)->minutes();
        $this->artisan('work:run')->assertSuccessful();
        $this->assertSame(2, $record->fresh()->doppelt);
    }
}
