<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Models\CustomTable;
use App\Models\ScheduledTask;
use App\Tasks\Types\SnapshotConnectionRefreshTask;
use App\Tasks\Types\SnapshotRefreshTask;
use Illuminate\Support\Collection;

/** Aktive geplante Aufgaben, die die Datenstände einer Tabelle aktualisieren (direkt oder über ihre Verbindung). */
final class SnapshotSchedules
{
    /** @return Collection<int, ScheduledTask> */
    public function for(CustomTable $table): Collection
    {
        // Wenige Aufgaben: in PHP filtern statt JSON-Abfragen je Datenbank-Dialekt.
        return ScheduledTask::query()->where('is_active', true)
            ->whereIn('type', [SnapshotRefreshTask::KEY, SnapshotConnectionRefreshTask::KEY])
            ->orderBy('next_run_at')
            ->get()
            ->filter(fn (ScheduledTask $task): bool => match ($task->type) {
                SnapshotRefreshTask::KEY => (int) ($task->parameterValues()['table_id'] ?? 0) === $table->id,
                default => (int) ($task->parameterValues()['connection_id'] ?? 0) === (int) $table->database_connection_id
                    && (! (bool) ($task->parameterValues()['only_with_snapshot'] ?? true) || $table->latestSnapshot() !== null),
            })
            ->values();
    }

    /**
     * Ab wann ein Datenstand als veraltet gilt: mit Zeitplan zwei Intervalle des häufigsten (der Zeitplan greift
     * offenbar nicht), sonst reports.snapshot_stale_hours.
     */
    public function staleHours(CustomTable $table): int
    {
        $interval = $this->for($table)->map(fn (ScheduledTask $task): int => $task->schedule()->intervalHours())->min();

        return $interval === null ? (int) config('reports.snapshot_stale_hours', 24) : 2 * (int) $interval;
    }
}
