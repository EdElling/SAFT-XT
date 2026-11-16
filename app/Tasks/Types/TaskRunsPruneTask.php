<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Models\TaskRun;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;
use Filament\Forms\Components\TextInput;

/** System-Aufgabe: Läufe im Protokoll löschen, die älter als die eingestellte Zahl von Tagen sind (Vorgabe 90). */
final readonly class TaskRunsPruneTask implements TaskType
{
    public const int DEFAULT_DAYS = 90;

    public function key(): string
    {
        return 'task_runs_prune';
    }

    public function label(): string
    {
        return __('tasks.types.task_runs_prune');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [
            TextInput::make('days')
                ->label(__('tasks.fields.keep_days'))
                ->helperText(__('tasks.fields.keep_days_help'))
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(self::DEFAULT_DAYS)
                ->required(),
        ];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.task_runs_prune', ['days' => $this->days($parameters)]);
    }

    public function run(array $parameters): TaskResult
    {
        $deleted = TaskRun::query()->where('started_at', '<', now()->subDays($this->days($parameters)))->delete();

        return new TaskResult(__('tasks.results.task_runs_prune', ['deleted' => $deleted]), ['deleted' => $deleted]);
    }

    /** @param  array<string, mixed>  $parameters */
    private function days(array $parameters): int
    {
        $days = $parameters['days'] ?? self::DEFAULT_DAYS;

        return is_numeric($days) ? max(1, (int) $days) : self::DEFAULT_DAYS;
    }
}
