<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use App\Tasks\Types\SessionsPruneTask;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDOException;
use Tests\TestCase;

/** System-Aufgabe „Sitzungen aufräumen“ statt Laravels Lotterie (B-177, Architekturentscheidung Nr. 32). */
class SessionsPruneTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        config(['session.lifetime' => 120]);
    }

    private function runTask(): TaskRun
    {
        $task = ScheduledTask::query()->where('type', SessionsPruneTask::KEY)->where('is_system', true)->sole();

        return app(TaskRunner::class)->run($task, TaskRun::MANUAL);
    }

    /** @param  list<string>  $ids */
    private function sessions(array $ids, int $lastActivity): void
    {
        foreach (array_chunk($ids, 200) as $chunk) {
            DB::table('sessions')->insert(array_map(static fn (string $id): array => [
                'id' => $id, 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => $lastActivity,
            ], $chunk));
        }
    }

    public function test_lottery_is_off_and_the_task_is_an_hourly_system_task(): void
    {
        $this->assertSame([0, 100], config('session.lottery'));

        $task = ScheduledTask::query()->where('type', SessionsPruneTask::KEY)->where('is_system', true)->sole();
        $this->assertSame(['hourly', '00:40', true], [$task->frequency, $task->at_time, $task->is_active]);
    }

    public function test_database_driver_deletes_expired_sessions_in_chunks_and_keeps_active_ones(): void
    {
        config(['session.driver' => 'database']);
        $expired = array_map(static fn (int $i): string => 'alt-'.$i, range(1, SessionsPruneTask::CHUNK + 3));
        $this->sessions($expired, now()->subMinutes(121)->getTimestamp());
        $this->sessions(['aktiv-1', 'aktiv-2'], now()->subMinutes(119)->getTimestamp());

        $run = $this->runTask();

        $this->assertSame(ScheduledTask::SUCCESS, $run->status);
        $this->assertSame('Sitzungen gelöscht: '.(SessionsPruneTask::CHUNK + 3).'.', $run->message);
        $this->assertSame(['aktiv-1', 'aktiv-2'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
    }

    public function test_a_deadlock_in_a_chunk_is_retried(): void
    {
        config(['session.driver' => 'database']);
        $this->sessions(['alt-1', 'alt-2'], now()->subDay()->getTimestamp());
        $deadlocks = 0;
        DB::connection()->beforeExecuting(static function (string $query) use (&$deadlocks): void {
            if ($deadlocks === 0 && str_starts_with(strtolower($query), 'delete from') && str_contains($query, 'sessions')) {
                $deadlocks++;
                $pdo = new PDOException('Deadlock found when trying to get lock', 40001);
                $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw new QueryException('mysql', $query, [], $pdo);
            }
        });

        $run = $this->runTask();

        $this->assertSame(1, $deadlocks);
        $this->assertSame([ScheduledTask::SUCCESS, 'Sitzungen gelöscht: 2.'], [$run->status, $run->message]);
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_file_driver_deletes_old_session_files(): void
    {
        $directory = storage_path('framework/testing/sessions-'.getmypid());
        File::ensureDirectoryExists($directory);
        File::cleanDirectory($directory);
        config(['session.driver' => 'file', 'session.files' => $directory]);
        File::put($directory.'/alt', 'x');
        touch($directory.'/alt', now()->subMinutes(121)->getTimestamp());
        File::put($directory.'/neu', 'x');

        try {
            $run = $this->runTask();

            $this->assertSame([ScheduledTask::SUCCESS, 'Sitzungen gelöscht: 1.'], [$run->status, $run->message]);
            $this->assertSame(['neu'], array_map(basename(...), File::files($directory)));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_self_expiring_drivers_are_skipped(): void
    {
        config(['session.driver' => 'array']);

        $run = $this->runTask();

        $this->assertSame(ScheduledTask::SKIPPED, $run->status);
        $this->assertSame('Der Sitzungstreiber „array“ lässt Sitzungen selbst ablaufen.', $run->message);
    }
}
