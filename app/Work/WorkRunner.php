<?php

declare(strict_types=1);

namespace App\Work;

use App\Auth\RoleSimulation;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\WorkItem;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginRegistry;
use App\Tasks\TaskRunner;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Treibt eine Arbeit voran (Fundament 2, F3a): nimmt die Sperre der Arbeit, führt Schritte aus, bis das Zeitbudget
 * aufgebraucht ist, und speichert nach jedem Schritt Zustand, Fortschritt und Heartbeat. Alle Treiber (Browser,
 * Scheduler, Konsole) rufen advance(); die Sperre sorgt dafür, dass nie zwei gleichzeitig an derselben Arbeit sind.
 *
 * Abgebrochene Prozesse: Vor jedem Schritt wird „stalls“ erhöht und gespeichert, nach dem Schritt zurückgesetzt. Endet
 * ein Prozess mitten im Schritt, bleibt der Zähler stehen; ab work.max_stalls gilt die Arbeit als fehlgeschlagen,
 * damit ein Schritt, der PHP jedes Mal abbricht, nicht endlos wiederholt wird.
 */
final readonly class WorkRunner
{
    public const string SCHEDULER_HEARTBEAT = 'work:heartbeat';

    public function __construct(
        private RecordWriter $writer,
        private RoleSimulation $simulation,
    ) {}

    public static function lockKey(WorkItem $item): string
    {
        return 'work:'.$item->id;
    }

    /** Schritte bis zum Ende des Budgets (mindestens einen); gibt den aktuellen Stand zurück. */
    public function advance(WorkItem $item, float $budgetSeconds): WorkItem
    {
        $lock = Cache::lock(self::lockKey($item), (int) ceil($budgetSeconds) + 120);

        if (! $lock->get()) {
            return $item->refresh();
        }

        try {
            $item->refresh();

            if (! $item->isOpen()) {
                return $item;
            }

            $deadline = microtime(true) + $budgetSeconds;

            $this->within($item, fn () => $this->steps($item, $deadline));

            return $item;
        } finally {
            $lock->release();
        }
    }

    private function steps(WorkItem $item, float $deadline): void
    {
        try {
            if ($item->cancel_requested_at !== null) {
                $this->finish($item, WorkItem::CANCELLED, __('work.cancelled'));

                return;
            }

            if ($item->stalls >= max(1, (int) config('work.max_stalls', 3))) {
                $this->finish($item, WorkItem::FAILED, __('work.aborted_repeatedly'));

                return;
            }

            $type = $item->type();
            $plugin = app(PluginRegistry::class)->ownerOf($type::class);

            // Arten aus einem ruhenden Plugin laufen nicht (wie bei geplanten Aufgaben).
            if ($plugin !== null && ! app(PluginDependencies::class)->satisfied($plugin)) {
                $this->finish($item, WorkItem::FAILED, __('work.plugin_inactive', ['plugin' => $plugin->label()]));

                return;
            }

            if ($item->status === WorkItem::QUEUED) {
                $this->prepare($item, $type);
            }

            do {
                $item->forceFill(['stalls' => $item->stalls + 1, 'heartbeat_at' => now()])->save();
                $step = new WorkStep($item->parameterValues(), (array) ($item->state ?? []), $deadline, $item->directory());
                $outcome = $type instanceof WritesRecords
                    ? $this->writer->batch(fn (): StepOutcome => $this->record($item, $type->step($step)), WriteOptions::defaults()->withOrigin(WriteOptions::WORK))
                    : $this->record($item, $type->step($step));

                if ($outcome->finished) {
                    $this->finish($item, WorkItem::DONE, (string) $outcome->message, $outcome->result);

                    return;
                }

                // Abbrechen kommt aus einer anderen Anfrage: frisch lesen.
                if (WorkItem::query()->whereKey($item->id)->whereNotNull('cancel_requested_at')->exists()) {
                    $this->finish($item, WorkItem::CANCELLED, __('work.cancelled'));

                    return;
                }
            } while (microtime(true) < $deadline);
        } catch (Throwable $e) {
            $this->fail($item, $e);
        }
    }

    private function prepare(WorkItem $item, WorkType $type): void
    {
        $item->forceFill(['status' => WorkItem::RUNNING, 'started_at' => now(), 'heartbeat_at' => now(), 'stalls' => $item->stalls + 1])->save();
        $plan = $type->prepare($item->parameterValues());
        $item->forceFill(['state' => $plan->state, 'total' => $plan->total, 'stalls' => 0])->save();
    }

    /** Fortschritt eines Schritts speichern; bei schreibenden Arten in der Transaktion des Schritts. */
    private function record(WorkItem $item, StepOutcome $outcome): StepOutcome
    {
        $item->forceFill([
            'state' => $outcome->finished ? $item->state : $outcome->state,
            'done' => $item->done + $outcome->done,
            'stalls' => 0,
            'heartbeat_at' => now(),
        ])->save();

        return $outcome;
    }

    private function fail(WorkItem $item, Throwable $e): void
    {
        // Referenz in Meldung, Arbeit und Protokoll, wie bei geplanten Aufgaben.
        $reference = Str::upper(Str::random(8));
        Log::warning('Arbeit fehlgeschlagen', ['work' => $item->uuid, 'type' => $item->type, 'error' => $e->getMessage(), 'error_reference' => $reference, 'exception' => $e]);
        $message = $e instanceof BusinessRuleException ? $e->getMessage() : __('work.failed_generic', ['reference' => $reference]);

        // Der Zustand im Speicher kann aus einer zurückgerollten Transaktion stammen: vom gespeicherten Stand ausgehen.
        $item->refresh();
        $this->finish($item, WorkItem::FAILED, $message, errorDetail: TaskRunner::errorDetail($e, $reference));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $errorDetail
     */
    public function finish(WorkItem $item, string $status, string $message, array $result = [], ?array $errorDetail = null): void
    {
        $item->forceFill([
            'status' => $status,
            'message' => $message,
            'result' => $result === [] ? null : $result,
            'error_detail' => $errorDetail,
            'state' => null,
            'stalls' => 0,
            'heartbeat_at' => now(),
            'started_at' => $item->started_at ?? now(),
            'finished_at' => now(),
            'expires_at' => $status === WorkItem::DONE ? now()->addHours(max(1, (int) config('work.result_hours', 24))) : null,
        ])->save();

        // Halbfertige Dateien abgebrochener und fehlgeschlagener Arbeiten braucht niemand.
        if ($status !== WorkItem::DONE) {
            Storage::disk('local')->deleteDirectory($item->directory());
        }
    }

    /**
     * Den Schritt als die startende Person in der App der Arbeit ausführen, mit den simulierten Rollen, falls unter
     * „Ansicht als Rolle“ gestartet. Die App wird nur im Speicher gesetzt (CurrentApp liest current_app_id), nicht
     * gespeichert. Danach gilt wieder, was vorher galt.
     *
     * @param  Closure(): void  $callback
     */
    private function within(WorkItem $item, Closure $callback): void
    {
        $guard = Auth::guard();
        $previous = $guard->user();
        $simulated = $this->simulation->active();
        $user = $item->user_id === null ? null : User::query()->find($item->user_id);

        if ($user !== null) {
            $user->current_app_id = $item->app_id ?? $user->current_app_id;

            if ($item->simulated_roles !== null) {
                $this->simulation->applyRoles($user, array_map('intval', (array) $item->simulated_roles));
            }

            $guard->setUser($user);
        } elseif ($guard instanceof SessionGuard) {
            $guard->forgetUser();
        }

        try {
            $callback();
        } finally {
            $this->simulation->restore($simulated);

            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif ($guard instanceof SessionGuard) {
                $guard->forgetUser();
            }
        }
    }
}
