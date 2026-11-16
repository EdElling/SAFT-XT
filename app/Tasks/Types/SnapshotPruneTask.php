<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\ExternalData\Snapshots\SnapshotService;
use App\Models\CustomTable;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;

/**
 * System-Aufgabe: Aufbewahrungsfristen aller Datenstände durchsetzen, auch ohne neue Aktualisierung
 * (Datenschutz), und abgebrochene Läufe aufräumen.
 */
final readonly class SnapshotPruneTask implements TaskType
{
    public function __construct(private SnapshotService $snapshots) {}

    public function key(): string
    {
        return 'snapshot_prune';
    }

    public function label(): string
    {
        return __('tasks.types.snapshot_prune');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.snapshot_prune');
    }

    public function run(array $parameters): TaskResult
    {
        $aborted = $this->snapshots->failHanging((int) config('tasks.snapshot_hanging_hours', 2));
        $deleted = 0;

        foreach (CustomTable::query()->external()->whereHas('snapshots')->get() as $table) {
            $deleted += $this->snapshots->prune($table);
        }

        return new TaskResult(__('tasks.results.snapshot_prune', ['deleted' => $deleted, 'aborted' => $aborted]), compact('deleted', 'aborted'));
    }
}
