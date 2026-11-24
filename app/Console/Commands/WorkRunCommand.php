<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WorkItem;
use App\Work\WorkRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Offene Arbeiten im Hintergrund vorantreiben (Fundament 2, F3a); der Scheduler ruft das jede Minute auf. Nimmt
 * Arbeiten, an denen seit work.takeover_seconds niemand mehr gearbeitet hat – solange die startende Person die Seite
 * offen hat, treibt deren Browser die Arbeit voran.
 */
#[Signature('work:run {--budget= : Zeitbudget in Sekunden (Vorgabe work.scheduler_budget)}')]
#[Description('Treibt offene Arbeiten im Hintergrund voran (vom Scheduler jede Minute aufgerufen)')]
class WorkRunCommand extends Command
{
    public function handle(WorkRunner $runner): int
    {
        Cache::forever(WorkRunner::SCHEDULER_HEARTBEAT, now()->toIso8601String());
        $budget = (float) ($this->option('budget') ?? config('work.scheduler_budget', 50));
        $deadline = microtime(true) + max(1.0, $budget);
        $seen = [];

        while (($remaining = $deadline - microtime(true)) > 0 && ($item = $this->next($seen)) !== null) {
            $seen[] = $item->id;
            $item = $runner->advance($item, $remaining);
            $this->line(sprintf('%s %s: %s (%d/%s)', $item->uuid, $item->type, __('work.status.'.$item->status), $item->done, $item->total ?? '?'));
        }

        return self::SUCCESS;
    }

    /** @param  list<int>  $seen  in diesem Aufruf schon bearbeitet */
    private function next(array $seen): ?WorkItem
    {
        $threshold = now()->subSeconds(max(0, (int) config('work.takeover_seconds', 30)));

        return WorkItem::query()->whereIn('status', WorkItem::OPEN)
            ->whereNotIn('id', $seen)
            ->where(fn (Builder $query) => $query
                ->where('heartbeat_at', '<', $threshold)
                ->orWhere(fn (Builder $query) => $query->whereNull('heartbeat_at')->where('created_at', '<', $threshold))
                // Abbrechen soll nicht auf den Browser warten.
                ->orWhereNotNull('cancel_requested_at'))
            ->orderBy('id')
            ->first();
    }
}
