<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Jobs\AdvanceWork;
use App\Livewire\WorkProgress;
use App\Models\WorkItem;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;
use Tests\Fixtures\Work\CountingWork;
use Tests\Fixtures\Work\LongWork;

/** Treiber „Queue“ (Fundament 2, F3b): Job je Arbeit, nur mit WORK_DRIVER=queue, der Browser zeigt dann nur noch an. */
class AdvanceWorkJobTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        config(['work.types' => [...(array) config('work.types'), CountingWork::class, LongWork::class], 'queue.default' => 'database']);
        CountingWork::$steps = 0;
        Queue::fake();
    }

    private function work(): WorkService
    {
        return app(WorkService::class);
    }

    /** Den Job ausführen wie ein Worker: Die Eindeutigkeitssperre fällt, sobald die Verarbeitung beginnt. */
    private function handle(int $workId): void
    {
        $job = new AdvanceWork($workId);
        (new UniqueLock(Cache::store()))->release($job);
        $job->handle(app(WorkRunner::class));
    }

    public function test_the_job_is_dispatched_only_with_the_queue_driver_on_a_real_queue_connection(): void
    {
        $this->work()->start(CountingWork::KEY, ['total' => 10, 'per_step' => 3]);
        Queue::assertNothingPushed();
        $this->assertFalse(WorkService::queueDriver(), 'Vorgabe: Browser und Scheduler treiben.');

        config(['work.driver' => 'queue']);
        $item = $this->work()->start(CountingWork::KEY, ['total' => 10, 'per_step' => 3]);
        Queue::assertPushed(AdvanceWork::class, fn (AdvanceWork $job): bool => $job->workId === $item->id && $job->uniqueId() === (string) $item->id);
        Queue::assertPushed(AdvanceWork::class, 1);

        // Auf „sync“ nie: Die Arbeit liefe sonst ganz im Request.
        config(['queue.default' => 'sync']);
        $this->work()->start(CountingWork::KEY, ['total' => 10, 'per_step' => 3]);
        Queue::assertPushed(AdvanceWork::class, 1);
        $this->assertFalse(WorkService::queueDriver());

        // Konsole und Aufgaben treiben selbst bis zum Ende: kein Job.
        config(['queue.default' => 'database']);
        $done = $this->work()->startAndDrive(CountingWork::KEY, ['total' => 10, 'per_step' => 3]);
        $this->assertSame(WorkItem::DONE, $done->status);
        Queue::assertPushed(AdvanceWork::class, 1);
    }

    public function test_the_job_advances_a_budget_long_and_queues_itself_again_until_the_work_is_done(): void
    {
        config(['work.driver' => 'queue', 'work.queue_budget' => 0]);
        $item = $this->work()->start(CountingWork::KEY, ['total' => 10, 'per_step' => 3]);
        Queue::assertPushed(AdvanceWork::class, 1);

        // Je Aufruf bis zum Ende des Budgets (mindestens 1 s): die Zählarbeit ist schnell, also fertig in einem Job, der
        // sich dann nicht mehr einreiht.
        $this->handle($item->id);
        $item->refresh();
        $this->assertSame([WorkItem::DONE, 4], [$item->status, CountingWork::$steps]);
        Queue::assertPushed(AdvanceWork::class, 1, 'Fertig: kein weiterer Job.');

        // Eine Arbeit, die ein Budget nicht schafft, reiht den nächsten Job mit Pause ein.
        $long = $this->work()->start(LongWork::KEY, ['seconds' => 0]);
        Queue::assertPushed(AdvanceWork::class, 2);
        $this->handle($long->id);
        $long->refresh();
        $this->assertSame([WorkItem::RUNNING, 1], [$long->status, $long->done], 'Ein langer Schritt je Aufruf.');
        Queue::assertPushed(AdvanceWork::class, 3);
        Queue::assertPushed(AdvanceWork::class, fn (AdvanceWork $job): bool => $job->workId === $long->id && $job->delay !== null);
        $this->handle($long->id);
        $this->assertSame(WorkItem::DONE, $long->refresh()->status);
        Queue::assertPushed(AdvanceWork::class, 3);

        // Eine beendete Arbeit: der Job tut nichts.
        $this->handle($long->id);
        Queue::assertPushed(AdvanceWork::class, 3);
    }

    public function test_the_job_timeout_covers_the_budget_or_a_long_step(): void
    {
        config(['work.driver' => 'queue', 'work.queue_budget' => 50]);
        $counting = $this->work()->start(CountingWork::KEY, ['total' => 1, 'per_step' => 1]);
        $long = $this->work()->start(LongWork::KEY, ['seconds' => 0]);

        $this->assertSame(80, (new AdvanceWork($counting->id))->timeout);
        $this->assertSame(1030, (new AdvanceWork($long->id))->timeout, 'stepSeconds() der Art plus Reserve.');
        $this->assertSame(80, (new AdvanceWork(999999))->timeout, 'Unbekannte Arbeit: nur das Budget.');
    }

    public function test_the_browser_only_watches_when_a_queue_worker_drives(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);
        config(['work.driver' => 'queue']);
        $item = $this->work()->start(CountingWork::KEY, ['total' => 10, 'per_step' => 3], $user);

        Livewire::test(WorkProgress::class, ['workId' => $item->id])
            ->call('advance')
            ->assertSee('Läuft im Hintergrund.')
            ->assertDontSee('solange diese Seite offen ist');
        $this->assertSame([WorkItem::QUEUED, 0], [$item->refresh()->status, $item->done], 'Der Browser treibt nicht.');

        // Ohne Queue-Treiber wie bisher.
        config(['work.driver' => 'auto']);
        Livewire::test(WorkProgress::class, ['workId' => $item->id])->call('advance');
        $this->assertSame(WorkItem::DONE, $item->refresh()->status);
    }
}
