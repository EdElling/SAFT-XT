<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\RelationManagers;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\Snapshots\SnapshotService;
use App\Filament\Resources\ScheduledTasks\ScheduledTaskResource;
use App\Filament\Support\RowIcons;
use App\Models\CustomTable;
use App\Models\DataSnapshot;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Tasks\SnapshotSchedules;
use App\Tasks\Types\SnapshotRefreshTask;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Datenstände einer angebundenen Tabelle: Liste, „Jetzt aktualisieren“, Aufbewahrung und Löschen.
 * Ansehen mit table_connector.view, alles Ändernde mit table_connector.update.
 */
class SnapshotsRelationManager extends RelationManager
{
    protected static string $relationship = 'snapshots';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('snapshots.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::allowed('view');
    }

    public function isReadOnly(): bool
    {
        return ! self::allowed('update');
    }

    private static function allowed(string $ability): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can("table_connector.{$ability}");
    }

    private function ownerTable(): CustomTable
    {
        /** @var CustomTable $table */
        $table = $this->getOwnerRecord();

        return $table;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** Zeitpläne, die diese Tabelle aktualisieren (direkt oder über die Verbindung), mit Link zur Aufgabe. */
    private function scheduleLine(): string
    {
        $tasks = app(SnapshotSchedules::class)->for($this->ownerTable());

        if ($tasks->isEmpty()) {
            return e(__('tasks.snapshot_no_schedule'));
        }

        $canView = self::user()?->can('tasks.view') ?? false;
        $items = $tasks->map(function (ScheduledTask $task) use ($canView): string {
            $text = e(__('tasks.snapshot_schedule_item', [
                'schedule' => $task->schedule()->describe(),
                'next' => $task->next_run_at?->format('d.m.Y H:i') ?? '–',
            ]));

            return $canView
                ? '<a class="underline" href="'.e(ScheduledTaskResource::getUrl('edit', ['record' => $task])).'">'.$text.'</a>'
                : $text;
        });

        return e(__('tasks.snapshot_schedules', ['schedules' => ''])).$items->implode('; ');
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): HtmlString => new HtmlString(e(__('snapshots.description')).'<br><span data-snapshot-schedule>'.$this->scheduleLine().'</span>'))
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->defaultSort('started_at', 'desc')
            ->emptyStateHeading(__('snapshots.none'))
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('snapshots.time'))
                    ->dateTime('d.m.Y H:i:s'),
                TextColumn::make('status')
                    ->label(__('snapshots.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('snapshots.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        DataSnapshot::COMPLETE => 'success',
                        DataSnapshot::FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('row_count')
                    ->label(__('snapshots.rows'))
                    ->numeric(thousandsSeparator: '\'')
                    ->alignEnd(),
                TextColumn::make('creator.name')->label(__('snapshots.by'))->placeholder(__('snapshots.automatic')),
                TextColumn::make('message')->label(__('snapshots.message'))->wrap(),
            ])
            ->headerActions([
                Action::make('setupSchedule')
                    ->label(__('tasks.setup_snapshot_schedule'))
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->visible(fn (): bool => self::user()?->can('tasks.create') ?? false)
                    ->url(fn (): string => ScheduledTaskResource::getUrl('create', ['type' => SnapshotRefreshTask::KEY, 'table' => $this->ownerTable()->id])),
                Action::make('refreshSnapshot')
                    ->label(__('snapshots.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->authorize(fn (): bool => self::allowed('update'))
                    ->action(function (): void {
                        try {
                            $snapshot = app(SnapshotService::class)->refresh($this->ownerTable(), self::user());
                            Notification::make()->success()
                                ->title(__('snapshots.refreshed', ['rows' => number_format((int) $snapshot->row_count, 0, ',', '\'')]))
                                ->send();
                        } catch (BusinessRuleException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('retention')
                    ->label(__('snapshots.retention'))
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->authorize(fn (): bool => self::allowed('update'))
                    ->fillForm(fn (): array => [
                        'snapshot_keep' => $this->ownerTable()->snapshot_keep,
                        'snapshot_max_days' => $this->ownerTable()->snapshot_max_days,
                    ])
                    ->schema([
                        TextInput::make('snapshot_keep')
                            ->label(__('snapshots.keep'))
                            ->helperText(__('snapshots.keep_help'))
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->maxValue(100),
                        TextInput::make('snapshot_max_days')
                            ->label(__('snapshots.max_days'))
                            ->helperText(__('snapshots.max_days_help'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3650),
                    ])
                    ->action(function (array $data): void {
                        $table = $this->ownerTable();
                        $table->update([
                            'snapshot_keep' => (int) $data['snapshot_keep'],
                            'snapshot_max_days' => filled($data['snapshot_max_days'] ?? null) ? (int) $data['snapshot_max_days'] : null,
                        ]);
                        app(SnapshotService::class)->prune($table);
                        Notification::make()->success()->title(__('snapshots.retention_saved'))->send();
                    }),
                Action::make('deleteAllSnapshots')
                    ->label(__('snapshots.delete_all'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('snapshots.delete_all_description'))
                    ->authorize(fn (): bool => self::allowed('update'))
                    ->visible(fn (): bool => $this->ownerTable()->snapshots()->exists())
                    ->action(function (): void {
                        app(SnapshotService::class)->deleteAll($this->ownerTable(), self::user());
                        Notification::make()->success()->title(__('snapshots.deleted_all'))->send();
                    }),
            ])
            ->recordActions(RowIcons::of([
                Action::make('deleteSnapshot')
                    ->label(__('snapshots.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => self::allowed('update'))
                    ->action(function (DataSnapshot $record): void {
                        app(SnapshotService::class)->delete($record, self::user());
                        Notification::make()->success()->title(__('snapshots.deleted'))->send();
                    }),
            ]));
    }
}
