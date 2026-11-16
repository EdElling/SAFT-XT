<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduledTask;
use App\Tasks\TaskRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Übersicht der geplanten Aufgaben mit Zeitplan, nächstem und letztem Lauf. */
#[Signature('tasks:list')]
#[Description('Listet die geplanten Aufgaben mit Zeitplan und Status')]
class TasksListCommand extends Command
{
    public function handle(TaskRunner $runner): int
    {
        $this->table(
            ['ID', __('tasks.fields.name'), __('tasks.fields.schedule'), __('tasks.fields.next_run'), __('tasks.fields.last_run'), __('tasks.fields.status')],
            ScheduledTask::query()->orderBy('id')->get()->map(fn (ScheduledTask $task): array => [
                $task->id,
                $task->displayName().(($d = $task->description()) !== '' ? " ({$d})" : ''),
                $task->is_active ? $task->schedule()->describe() : __('tasks.inactive'),
                $task->next_run_at?->format('d.m.Y H:i') ?? '–',
                $task->last_run_at?->format('d.m.Y H:i') ?? '–',
                $task->last_status !== null ? __('tasks.status.'.$task->last_status) : '–',
            ])->all(),
        );

        $last = $runner->lastHeartbeat();
        $this->line(__('tasks.console.heartbeat', ['time' => $last?->setTimezone((string) config('app.timezone'))->format('d.m.Y H:i') ?? '–']));

        if (! $runner->schedulerAlive() && ScheduledTask::query()->where('is_active', true)->exists()) {
            $this->warn(__('tasks.scheduler_missing'));
        }

        return self::SUCCESS;
    }
}
