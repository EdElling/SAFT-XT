<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\ScheduledTasks\ScheduledTaskResource;
use App\Filament\Resources\ScheduledTasks\Tables\ScheduledTasksTable;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\WorkItem;
use App\Tasks\TaskRunner;
use App\Tasks\Types\ActivityPruneTask;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

/**
 * Geplante Aufgaben auf dem Dashboard: Ampel (Scheduler läuft nicht / Aufgaben fehlgeschlagen / alles in Ordnung)
 * und die letzten Läufe. Rechte widgets.tasks und tasks.view.
 */
class TasksStatusWidget extends TableWidget
{
    use RequiresPermissions;

    private const int RUNS = 8;

    protected static ?int $sort = 25;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermissions(): array
    {
        return ['widgets.tasks', 'tasks.view'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('tasks.plural'))
            ->description(fn (): HtmlString => $this->status())
            // Die letzten Läufe; fehlgeschlagene Aufgaben nennt der Kopf (status()).
            ->query(fn () => TaskRun::query()->with('task')
                ->whereIn('id', TaskRun::query()->orderByDesc('started_at')->orderByDesc('id')->limit(self::RUNS)->pluck('id')))
            ->defaultSort(fn ($query) => $query->orderByDesc('started_at')->orderByDesc('id'))
            ->paginated(false)
            ->emptyStateHeading(__('tasks.widget.empty'))
            ->recordUrl(fn (TaskRun $record): ?string => $record->task !== null && ScheduledTaskResource::canEdit($record->task)
                ? ScheduledTaskResource::getUrl('edit', ['record' => $record->task])
                : null)
            ->columns([
                TextColumn::make('task')
                    ->label(__('tasks.widget.task'))
                    ->state(fn (TaskRun $record): string => $record->task?->displayName() ?? '–')
                    ->description(fn (TaskRun $record): string => $record->task?->description() ?? ''),
                TextColumn::make('status')
                    ->label(__('tasks.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('tasks.status.'.$state))
                    ->color(fn (string $state): string => ScheduledTasksTable::color($state)),
                TextColumn::make('started_at')
                    ->label(__('tasks.widget.when'))
                    ->since()
                    ->tooltip(fn (TaskRun $record): string => $record->started_at->format('d.m.Y H:i:s')),
                TextColumn::make('message')->label(__('tasks.fields.message'))->wrap()->limit(160),
            ]);
    }

    /** Ampel als Satz: zuerst ein fehlender Scheduler, dann fehlgeschlagene Aufgaben; dazu der Hinweis auf das ungekürzte Änderungsprotokoll. */
    private function status(): HtmlString
    {
        $failing = ScheduledTask::query()->where('is_active', true)->where('consecutive_failures', '>', 0)->get();
        $missing = ! app(TaskRunner::class)->schedulerAlive() && ScheduledTask::query()->where('is_active', true)->exists();
        $line = fn (string $color, string $icon, string $text, string $key): string => '<span class="'.$color.'" data-tasks-status="'.$key.'">'.$icon.' '.e($text).'</span>';

        $parts = [];

        if ($missing) {
            $parts[] = $line('text-warning-600 dark:text-warning-400 font-medium', '⚠', __('tasks.scheduler_missing'), 'scheduler');
        }

        if ($failing->isNotEmpty()) {
            $parts[] = $line('text-danger-600 dark:text-danger-400 font-medium', '✖', __('tasks.widget.failing', ['tasks' => $failing->map->displayName()->implode(', ')]), 'failing');
        }

        // Arbeiten (Fundament 2, F3a): ohne Fortschritt seit work.stuck_minutes oder kürzlich fehlgeschlagen.
        $minutes = (int) config('work.stuck_minutes', 10);
        $stuck = WorkItem::query()->whereIn('status', WorkItem::OPEN)
            ->where(fn ($query) => $query->where('heartbeat_at', '<', now()->subMinutes($minutes))
                ->orWhere(fn ($query) => $query->whereNull('heartbeat_at')->where('created_at', '<', now()->subMinutes($minutes))))
            ->count();
        $failedWork = WorkItem::query()->where('status', WorkItem::FAILED)->where('finished_at', '>=', now()->subDay())->count();

        if ($stuck > 0) {
            $parts[] = $line('text-warning-600 dark:text-warning-400 font-medium', '⚠', trans_choice('work.widget.stuck', $stuck, ['count' => $stuck, 'minutes' => $minutes]), 'work-stuck');
        }

        if ($failedWork > 0) {
            $parts[] = $line('text-danger-600 dark:text-danger-400 font-medium', '✖', trans_choice('work.widget.failed', $failedWork, ['count' => $failedWork]), 'work-failed');
        }

        $parts = $parts === [] ? [$line('text-success-600 dark:text-success-400', '✔', __('tasks.widget.ok'), 'ok')] : $parts;

        // Änderungsprotokoll kürzen ist ausgeliefert aus (löscht Personendaten endgültig); solange es alte Einträge gibt, steht das hier.
        $prune = ScheduledTask::query()->where('type', ActivityPruneTask::KEY)->where('is_system', true)->first();

        if ($prune !== null && ! $prune->is_active && ActivityPruneTask::hasOverdueEntries($prune->parameterValues())) {
            $parts[] = $line('text-gray-600 dark:text-gray-400', 'ℹ', __('tasks.widget.activity_hint', ['days' => ActivityPruneTask::days($prune->parameterValues())]), 'activity');
        }

        return new HtmlString(implode('<br>', $parts));
    }
}
