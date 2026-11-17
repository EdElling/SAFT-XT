<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledTasks\RelationManagers;

use App\Filament\Resources\ScheduledTasks\Tables\ScheduledTasksTable;
use App\Models\TaskRun;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/** Verlauf der Läufe einer Aufgabe (nur lesen). */
class TaskRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tasks.runs');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('tasks.view') ?? false;
    }

    /** Nach „Jetzt ausführen“ auf der Seite neu zeichnen. */
    #[On('task-ran')]
    public function refreshRuns(): void {}

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label(__('tasks.fields.started'))->dateTime('d.m.Y H:i:s'),
                TextColumn::make('trigger')
                    ->label(__('tasks.fields.trigger'))
                    ->formatStateUsing(fn (string $state, TaskRun $record): string => __('tasks.trigger.'.$state).($record->user !== null ? ' · '.$record->user->name : '')),
                TextColumn::make('status')
                    ->label(__('tasks.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('tasks.status.'.$state))
                    ->color(fn (string $state): string => ScheduledTasksTable::color($state)),
                TextColumn::make('duration')
                    ->label(__('tasks.fields.duration'))
                    ->state(fn (TaskRun $record): string => ($s = $record->durationSeconds()) === null ? '–' : gmdate($s >= 3600 ? 'G:i:s' : 'i:s', $s))
                    ->alignEnd(),
                TextColumn::make('message')->label(__('tasks.fields.message'))->wrap(),
            ])
            ->recordActions([
                // Technische Details eines Fehlschlags (Referenz, Fehler, Ort, Ablauf) – nur mit Recht tasks.details.
                Action::make('errorDetails')
                    ->label(__('tasks.error_detail.action'))
                    ->icon('heroicon-o-bug-ant')
                    ->color('gray')
                    ->visible(fn (TaskRun $record): bool => $record->error_detail !== null && self::canSeeDetails())
                    ->modalHeading(fn (TaskRun $record): string => __('tasks.error_detail.heading', ['reference' => (string) ($record->error_detail['reference'] ?? '')]))
                    ->modalContent(fn (TaskRun $record): View => view('filament.tasks.error-detail', ['detail' => (array) $record->error_detail]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('tasks.error_detail.close'))
                    ->modalWidth(Width::FourExtraLarge)
                    ->action(function (): void {
                        abort_unless(self::canSeeDetails(), 403);
                    }),
            ]);
    }

    public static function canSeeDetails(): bool
    {
        return auth()->user()?->can('tasks.details') ?? false;
    }
}
