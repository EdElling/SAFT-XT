<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Models\WorkItem;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Storage;

/**
 * System-Aufgabe „Arbeiten aufräumen“ (Fundament 2, F3a), stündlich: löscht Ergebnisdateien nach Ablauf
 * (work_items.expires_at, Vorgabe 24 h nach dem Ende), beendete Arbeiten nach der eingestellten Zahl von Tagen
 * (Vorgabe 30) und Ordner unter work/ ohne Arbeit.
 */
final readonly class WorkPruneTask implements TaskType
{
    public const string KEY = 'work_prune';

    public const int DEFAULT_DAYS = 30;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tasks.types.work_prune');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [
            TextInput::make('days')
                ->label(__('tasks.fields.work_keep_days'))
                ->helperText(__('tasks.fields.work_keep_days_help'))
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(self::DEFAULT_DAYS)
                ->required(),
        ];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.work_prune', ['days' => $this->days($parameters)]);
    }

    public function run(array $parameters): TaskResult
    {
        $disk = Storage::disk('local');
        $files = 0;

        // Abgelaufene Ergebnisse: Ordner weg, die Arbeit bleibt (mit Meldung) bis zum Ablauf der Tage.
        foreach (WorkItem::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->pluck('uuid') as $uuid) {
            if ($disk->exists('work/'.$uuid)) {
                $disk->deleteDirectory('work/'.$uuid);
                $files++;
            }
        }

        $old = WorkItem::query()->whereNotIn('status', WorkItem::OPEN)->where('updated_at', '<', now()->subDays($this->days($parameters)));

        foreach ((clone $old)->pluck('uuid') as $uuid) {
            $disk->deleteDirectory('work/'.$uuid);
        }

        $deleted = $old->delete();

        // Ordner ohne Arbeit (z. B. nach Abbruch des Prozesses beim Anlegen).
        $known = WorkItem::query()->pluck('uuid')->flip();

        foreach ($disk->directories('work') as $directory) {
            if (! $known->has(basename($directory))) {
                $disk->deleteDirectory($directory);
                $files++;
            }
        }

        return new TaskResult(__('tasks.results.work_prune', ['deleted' => $deleted, 'files' => $files]), ['deleted' => $deleted, 'files' => $files]);
    }

    /** @param  array<string, mixed>  $parameters */
    private function days(array $parameters): int
    {
        $days = $parameters['days'] ?? self::DEFAULT_DAYS;

        return is_numeric($days) ? max(1, (int) $days) : self::DEFAULT_DAYS;
    }
}
