<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Exceptions\BusinessRuleException;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Führt geplante Aufgaben aus: fällige (Scheduler, jede Minute) oder einzelne (von Hand, Konsole).
 * Jeder Lauf wird protokolliert; eine Aufgabe läuft nie doppelt. Bei einem Fehlschlag nach einem Erfolg und
 * bei der ersten Erholung danach werden die Super-Admins benachrichtigt.
 */
final readonly class TaskRunner
{
    private const string HEARTBEAT = 'tasks:heartbeat';

    public function __construct(private TaskNotifier $notifier) {}

    /** @return list<TaskRun> */
    public function runDue(): array
    {
        Cache::forever(self::HEARTBEAT, now()->toIso8601String());
        $this->failHanging();

        // Aufgaben ohne Termin (z. B. aus einer Migration) bekommen ihren ersten Termin, ohne sofort zu laufen.
        ScheduledTask::query()->where('is_active', true)->whereNull('next_run_at')->get()->each->save();

        $runs = [];
        $due = ScheduledTask::query()->where('is_active', true)->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())->orderBy('next_run_at')->orderBy('id')->get();

        foreach ($due as $task) {
            $runs[] = $this->run($task, TaskRun::SCHEDULE);
        }

        return $runs;
    }

    public function run(ScheduledTask $task, string $trigger, ?User $user = null): TaskRun
    {
        $lock = Cache::lock('tasks:run:'.$task->id, max(1, (int) config('tasks.hanging_hours', 6)) * 3600);

        if (! $lock->get()) {
            return $this->finish($task, $this->start($task, $trigger, $user), ScheduledTask::SKIPPED, __('tasks.already_running'));
        }

        try {
            $run = $this->start($task, $trigger, $user);
            @set_time_limit(0);

            try {
                $this->assertPluginActive($task);
                $result = $task->type()->run($task->parameterValues());

                return $this->finish($task, $run, ScheduledTask::SUCCESS, $result->message, $result->data);
            } catch (TaskSkipped $e) {
                return $this->finish($task, $run, ScheduledTask::SKIPPED, $e->getMessage());
            } catch (Throwable $e) {
                Log::warning('Geplante Aufgabe fehlgeschlagen', ['task' => $task->id, 'type' => $task->type, 'error' => $e->getMessage()]);
                $message = $e instanceof BusinessRuleException ? $e->getMessage() : __('tasks.failed_generic');

                return $this->finish($task, $run, ScheduledTask::FAILED, $message);
            }
        } finally {
            $lock->release();
        }
    }

    /** Arten aus einem ausgeschalteten oder ruhenden Plugin laufen nicht; der Lauf gilt als übersprungen. */
    private function assertPluginActive(ScheduledTask $task): void
    {
        $plugin = app(TaskRegistry::class)->has($task->type) ? app(PluginRegistry::class)->ownerOf($task->type()::class) : null;

        if ($plugin !== null && ! app(PluginDependencies::class)->satisfied($plugin)) {
            throw new TaskSkipped(__('tasks.plugin_inactive', ['plugin' => $plugin->label()]));
        }
    }

    /** Läuft der Scheduler (letzter Aufruf innerhalb von tasks.heartbeat_minutes)? */
    public function schedulerAlive(): bool
    {
        $last = $this->lastHeartbeat();

        return $last !== null && $last->greaterThan(now()->subMinutes((int) config('tasks.heartbeat_minutes', 15)));
    }

    public function lastHeartbeat(): ?CarbonImmutable
    {
        $value = Cache::get(self::HEARTBEAT);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /** Läufe, die seit tasks.hanging_hours auf „läuft“ stehen (abgebrochener Prozess), gelten als fehlgeschlagen. */
    public function failHanging(): int
    {
        return TaskRun::query()->where('status', TaskRun::RUNNING)
            ->where('started_at', '<', now()->subHours((int) config('tasks.hanging_hours', 6)))
            ->update(['status' => ScheduledTask::FAILED, 'finished_at' => now(), 'message' => __('tasks.aborted')]);
    }

    private function start(ScheduledTask $task, string $trigger, ?User $user): TaskRun
    {
        return $task->runs()->create([
            'trigger' => $trigger,
            'triggered_by' => $user?->id,
            'status' => TaskRun::RUNNING,
            'started_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function finish(ScheduledTask $task, TaskRun $run, string $status, string $message, array $data = []): TaskRun
    {
        $run->update(['status' => $status, 'finished_at' => now(), 'message' => $message, 'result' => $data === [] ? null : $data]);

        $previousFailures = (int) $task->consecutive_failures;
        $failures = match ($status) {
            ScheduledTask::FAILED => $previousFailures + 1,
            ScheduledTask::SUCCESS => 0,
            default => $previousFailures,
        };

        $task->forceFill([
            'last_run_at' => $run->started_at,
            'last_status' => $status,
            'consecutive_failures' => $failures,
            // Geplanter Lauf: nächster Termin ab jetzt (verpasste Termine werden nicht einzeln nachgeholt).
            'next_run_at' => $run->trigger === TaskRun::SCHEDULE && $task->is_active ? $task->schedule()->next(now()) : $task->next_run_at,
        ])->save();

        if ($status === ScheduledTask::FAILED && $previousFailures === 0) {
            $this->notifier->failed($task, $run);
        } elseif ($status === ScheduledTask::SUCCESS && $previousFailures > 0) {
            $this->notifier->recovered($task, $run);
        }

        return $run;
    }
}
