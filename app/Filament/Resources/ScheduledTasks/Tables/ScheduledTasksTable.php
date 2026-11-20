<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledTasks\Tables;

use App\Filament\Support\AppFilter;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\TaskRunner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScheduledTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label(__('tasks.fields.name'))
                    ->state(fn (ScheduledTask $record): string => $record->displayName())
                    ->description(fn (ScheduledTask $record): string => $record->description())
                    ->wrap(),
                TextColumn::make('frequency')
                    ->label(__('tasks.fields.schedule'))
                    ->state(fn (ScheduledTask $record): string => $record->is_active ? $record->schedule()->describe() : __('tasks.inactive'))
                    ->color(fn (ScheduledTask $record): ?string => $record->is_active ? null : 'gray')
                    ->wrap(),
                TextColumn::make('next_run_at')
                    ->label(__('tasks.fields.next_run'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('–'),
                TextColumn::make('last_status')
                    ->label(__('tasks.fields.last_run'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('tasks.status.'.$state))
                    ->color(fn (string $state): string => self::color($state))
                    ->description(fn (ScheduledTask $record): string => $record->last_run_at?->format('d.m.Y H:i') ?? '')
                    ->placeholder('–'),
                TextColumn::make('consecutive_failures')
                    ->label(__('tasks.fields.failures'))
                    ->numeric()
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null)
                    ->toggleable(),
                AppFilter::column(),
            ])
            ->recordActions([
                Action::make('runNow')
                    ->label(__('tasks.run_now'))
                    ->tooltip(__('tasks.run_now'))
                    ->iconButton()
                    ->icon('heroicon-o-play')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ScheduledTask $record): string => __('tasks.run_now_confirm', ['task' => $record->displayName()]))
                    ->authorize(fn (): bool => auth()->user()?->can('tasks.update') ?? false)
                    ->action(fn (ScheduledTask $record) => self::runNow($record)),
                EditAction::make()->iconButton()->tooltip(__('filament-actions::edit.single.label')),
                DeleteAction::make()->iconButton()->tooltip(__('filament-actions::delete.single.label'))
                    ->visible(fn (ScheduledTask $record): bool => ! $record->is_system),
            ]);
    }

    public static function color(string $status): string
    {
        return match ($status) {
            ScheduledTask::SUCCESS => 'success',
            ScheduledTask::FAILED => 'danger',
            TaskRun::RUNNING => 'warning',
            default => 'gray',
        };
    }

    /** „Jetzt ausführen“ – auch auf der Bearbeitungsseite. */
    public static function runNow(ScheduledTask $task): void
    {
        $user = auth()->user();
        $run = app(TaskRunner::class)->run($task, TaskRun::MANUAL, $user instanceof User ? $user : null);

        Notification::make()
            ->title(($run->status === ScheduledTask::SUCCESS ? __('tasks.run_finished') : __('tasks.status.'.$run->status)).': '.$task->displayName())
            ->body($run->message)
            ->status(match ($run->status) {
                ScheduledTask::SUCCESS => 'success',
                ScheduledTask::FAILED => 'danger',
                default => 'warning',
            })
            ->send();
    }
}
