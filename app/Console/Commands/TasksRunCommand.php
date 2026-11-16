<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Eine geplante Aufgabe sofort ausführen (unabhängig vom Zeitplan; der nächste Termin bleibt). */
#[Signature('tasks:run {task : ID der Aufgabe (siehe tasks:list)}')]
#[Description('Führt eine geplante Aufgabe sofort aus')]
class TasksRunCommand extends Command
{
    public function handle(TaskRunner $runner): int
    {
        $task = ScheduledTask::query()->find((int) $this->argument('task'));

        if ($task === null) {
            $this->error(__('tasks.console.not_found', ['id' => (string) $this->argument('task')]));

            return self::FAILURE;
        }

        $run = $runner->run($task, TaskRun::CONSOLE);
        $this->line(sprintf('%s: %s – %s', $task->displayName(), __('tasks.status.'.$run->status), (string) $run->message));

        return $run->status === ScheduledTask::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
