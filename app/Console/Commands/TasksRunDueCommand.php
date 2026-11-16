<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduledTask;
use App\Tasks\TaskRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Fällige geplante Aufgaben ausführen; der Laravel-Scheduler ruft das jede Minute auf. */
#[Signature('tasks:run-due')]
#[Description('Führt die fälligen geplanten Aufgaben aus (vom Scheduler jede Minute aufgerufen)')]
class TasksRunDueCommand extends Command
{
    public function handle(TaskRunner $runner): int
    {
        $runs = $runner->runDue();

        foreach ($runs as $run) {
            $this->line(sprintf('%s: %s – %s', $run->task?->displayName(), __('tasks.status.'.$run->status), (string) $run->message));
        }

        $failed = collect($runs)->where('status', ScheduledTask::FAILED)->count();

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
