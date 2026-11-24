<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WorkItem;
use App\Work\LongSteps;
use App\Work\WorkRegistry;
use App\Work\WorkRunner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Treiber „Queue“ (Fundament 2, F3b): treibt eine Arbeit ein Zeitbudget lang voran (work.queue_budget) und reiht sich
 * wieder ein, solange sie offen ist. Je Arbeit höchstens ein Job in der Warteschlange; die Sperre des Runners schützt
 * vor gleichzeitigen Treibern. Fehler eines Schritts behandelt der Runner (Arbeit „fehlgeschlagen“), der Job selbst
 * meldet keinen. Aktiv nur mit WORK_DRIVER=queue (WorkService::start()); fällt der Worker aus, übernimmt der
 * Scheduler (work:run) nach work.takeover_seconds wie bisher.
 */
final class AdvanceWork implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Pause, bevor der nächste Job dieselbe Arbeit weitertreibt (ein anderer Treiber könnte die Sperre halten). */
    public const int PAUSE_SECONDS = 2;

    public int $tries = 1;

    /** Budget plus Reserve, bei langen Schritten deren Dauer. */
    public int $timeout;

    public function __construct(public readonly int $workId)
    {
        $this->timeout = self::timeoutFor(WorkItem::query()->find($workId));
    }

    public static function budget(): float
    {
        return max(1.0, (float) config('work.queue_budget', 50));
    }

    /** Sekunden, die ein Job für eine Arbeit höchstens braucht. */
    public static function timeoutFor(?WorkItem $item): int
    {
        $registry = app(WorkRegistry::class);
        $type = $item !== null && $registry->has($item->type) ? $registry->get($item->type) : null;
        $seconds = $type instanceof LongSteps ? max((int) ceil(self::budget()), $type->stepSeconds()) : (int) ceil(self::budget());

        return $seconds + 30;
    }

    public function uniqueId(): string
    {
        return (string) $this->workId;
    }

    public function handle(WorkRunner $runner): void
    {
        $item = WorkItem::query()->find($this->workId);

        if ($item === null || ! $item->isOpen()) {
            return;
        }

        $item = $runner->advance($item, self::budget());

        if ($item->isOpen()) {
            self::dispatch($item->id)->delay(now()->addSeconds(self::PAUSE_SECONDS));
        }
    }
}
