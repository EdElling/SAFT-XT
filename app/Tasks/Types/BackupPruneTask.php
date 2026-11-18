<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Database\DatabaseBackup;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;
use Filament\Forms\Components\TextInput;

/** System-Aufgabe: alte Sicherungen in storage/backups löschen; die neuesten bleiben immer. */
final readonly class BackupPruneTask implements TaskType
{
    public const int DEFAULT_DAYS = 14;

    public const int DEFAULT_KEEP = 3;

    public function __construct(private DatabaseBackup $backup) {}

    public function key(): string
    {
        return 'backup_prune';
    }

    public function label(): string
    {
        return __('tasks.types.backup_prune');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [
            TextInput::make('days')
                ->label(__('tasks.fields.backup_days'))
                ->helperText(__('tasks.fields.backup_days_help'))
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(self::DEFAULT_DAYS)
                ->required(),
            TextInput::make('keep')
                ->label(__('tasks.fields.backup_keep'))
                ->helperText(__('tasks.fields.backup_keep_help'))
                ->integer()
                ->minValue(1)
                ->maxValue(1000)
                ->default(self::DEFAULT_KEEP)
                ->required(),
        ];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.backup_prune', ['days' => $this->days($parameters), 'keep' => $this->keep($parameters)]);
    }

    public function run(array $parameters): TaskResult
    {
        $deleted = $this->backup->prune($this->days($parameters), $this->keep($parameters));

        // Eine Sicherung mit ZIP der Dateien zählt einmal.
        $sets = count(array_unique(array_map(DatabaseBackup::setName(...), $deleted)));

        return new TaskResult(__('tasks.results.backup_prune', ['deleted' => $sets]), ['deleted' => $deleted]);
    }

    /** @param  array<string, mixed>  $parameters */
    private function days(array $parameters): int
    {
        $days = $parameters['days'] ?? self::DEFAULT_DAYS;

        return is_numeric($days) ? max(1, (int) $days) : self::DEFAULT_DAYS;
    }

    /** @param  array<string, mixed>  $parameters */
    private function keep(array $parameters): int
    {
        $keep = $parameters['keep'] ?? self::DEFAULT_KEEP;

        return is_numeric($keep) ? max(1, (int) $keep) : self::DEFAULT_KEEP;
    }
}
