<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\TaskRun as BaseTaskRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ein Lauf einer geplanten Aufgabe (Protokoll; Aufbewahrung über die System-Aufgabe „Laufprotokoll bereinigen“). */
class TaskRun extends BaseTaskRun
{
    public const string RUNNING = 'running';

    public const string SCHEDULE = 'schedule';

    public const string MANUAL = 'manual';

    public const string CONSOLE = 'console';

    /** @return BelongsTo<ScheduledTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class, 'scheduled_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function durationSeconds(): ?int
    {
        return $this->finished_at === null ? null : (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
