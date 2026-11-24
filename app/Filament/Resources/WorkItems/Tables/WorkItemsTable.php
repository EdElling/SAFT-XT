<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkItems\Tables;

use App\Filament\Support\RowIcons;
use App\Models\WorkItem;
use App\Work\WorkService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class WorkItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Solange etwas läuft, aktualisiert sich die Liste selbst.
            ->poll(fn (): ?string => WorkItem::query()->whereIn('status', WorkItem::OPEN)->exists() ? '5s' : null)
            ->emptyStateHeading(__('work.empty'))
            ->columns([
                TextColumn::make('type')
                    ->label(__('work.fields.type'))
                    ->state(fn (WorkItem $record): string => $record->typeLabel())
                    ->description(fn (WorkItem $record): string => $record->description()),
                TextColumn::make('user.name')
                    ->label(__('work.fields.user'))
                    ->placeholder(__('work.system'))
                    ->toggleable(),
                TextColumn::make('app.name')
                    ->label(__('work.fields.app'))
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('work.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('work.status.'.$state))
                    ->color(fn (string $state): string => self::color($state)),
                TextColumn::make('done')
                    ->label(__('work.fields.progress'))
                    ->state(fn (WorkItem $record): string => self::progress($record)),
                TextColumn::make('created_at')
                    ->label(__('work.fields.created'))
                    ->since()
                    ->tooltip(fn (WorkItem $record): string => (string) $record->created_at?->format('d.m.Y H:i:s'))
                    ->sortable(),
                TextColumn::make('duration')
                    ->label(__('work.fields.duration'))
                    ->state(fn (WorkItem $record): string => self::duration($record->durationSeconds()))
                    ->alignEnd(),
                TextColumn::make('message')
                    ->label(__('work.fields.message'))
                    ->wrap()
                    ->limit(160)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('work.fields.status'))
                    ->options(collect([WorkItem::QUEUED, WorkItem::RUNNING, WorkItem::DONE, WorkItem::FAILED, WorkItem::CANCELLED])
                        ->mapWithKeys(fn (string $status): array => [$status => __('work.status.'.$status)])->all()),
            ])
            ->recordActions(RowIcons::of([
                ViewAction::make(),
                self::downloadAction(),
                self::cancelAction(),
                self::detailsAction(),
            ]));
    }

    public static function color(string $status): string
    {
        return match ($status) {
            WorkItem::RUNNING => 'info',
            WorkItem::DONE => 'success',
            WorkItem::FAILED => 'danger',
            WorkItem::CANCELLED => 'warning',
            default => 'gray',
        };
    }

    /** „1'240 / 5'000 (24 %)“, ohne bekannte Menge nur die erledigte. */
    public static function progress(WorkItem $record): string
    {
        $number = static fn (int $value): string => number_format($value, 0, '.', "'");

        return $record->total === null
            ? $number($record->done)
            : $number($record->done).' / '.$number($record->total).' ('.($record->percent() ?? 0).' %)';
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }

        return gmdate($seconds >= 3600 ? 'G:i:s' : 'i:s', $seconds);
    }

    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('work.actions.download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (WorkItem $record): bool => $record->resultFile() !== null)
            ->url(fn (WorkItem $record): string => route('work.download', $record->uuid));
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('work.actions.cancel'))
            ->icon('heroicon-o-stop-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('work.actions.cancel_heading'))
            ->visible(fn (WorkItem $record): bool => auth()->user()?->can('cancel', $record) ?? false)
            ->action(function (WorkItem $record): void {
                abort_unless(auth()->user()?->can('cancel', $record) ?? false, 403);
                app(WorkService::class)->cancel($record);
                Notification::make()->title(__('work.actions.cancel_requested'))->success()->send();
            });
    }

    public static function detailsAction(): Action
    {
        // Technische Details eines Fehlschlags (Referenz, Fehler, Ort, Ablauf) – nur mit Recht work.details.
        return Action::make('details')
            ->label(__('tasks.error_detail.action'))
            ->icon('heroicon-o-bug-ant')
            ->color('gray')
            ->visible(fn (WorkItem $record): bool => auth()->user()?->can('details', $record) ?? false)
            ->modalHeading(fn (WorkItem $record): string => __('tasks.error_detail.heading', ['reference' => (string) ($record->error_detail['reference'] ?? '')]))
            ->modalContent(fn (WorkItem $record): View => view('filament.tasks.error-detail', ['detail' => (array) $record->error_detail]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('tasks.error_detail.close'))
            ->modalWidth(Width::FourExtraLarge)
            ->action(fn (WorkItem $record) => abort_unless(auth()->user()?->can('details', $record) ?? false, 403));
    }
}
