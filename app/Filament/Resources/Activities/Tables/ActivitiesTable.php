<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Tables;

use App\Filament\Resources\Activities\Schemas\ActivityEventColor;
use App\Filament\Support\ActivityChanges;
use App\Filament\Support\RowIcons;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns(self::columns())
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('causer_id')
                    ->label(__('activity.user'))
                    ->options(fn (): array => User::withTrashed()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, mixed $id): Builder => $q->where('causer_type', User::class)->where('causer_id', $id),
                    ))
                    ->searchable(),
                SelectFilter::make('event')
                    ->label(__('activity.event'))
                    ->options(ActivityChanges::eventOptions()),
                SelectFilter::make('subject_type')
                    ->label(__('activity.subject_type'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->orderBy('subject_type')
                        ->pluck('subject_type')
                        ->mapWithKeys(fn (string $type): array => [$type => ActivityChanges::typeLabel($type)])
                        ->all()),
                Filter::make('created_at')
                    ->label(__('activity.period'))
                    ->schema([
                        DatePicker::make('from')->label(__('activity.from')),
                        DatePicker::make('until')->label(__('activity.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions(RowIcons::of([
                ViewAction::make(),
            ]));
    }

    /**
     * Gemeinsame Spalten für die Resource und den Relation Manager.
     *
     * @return list<TextColumn>
     */
    public static function columns(bool $withSubject = true): array
    {
        return array_values(array_filter([
            TextColumn::make('created_at')
                ->label(__('data.history.when'))
                ->dateTime()
                ->sortable(),
            TextColumn::make('causer_label')
                ->label(__('activity.user'))
                ->state(fn (Activity $record): string => ActivityChanges::causerLabel($record)),
            TextColumn::make('event')
                ->label(__('activity.event'))
                ->badge()
                ->formatStateUsing(fn (?string $state, Activity $record): string => ActivityChanges::eventText($record))
                ->color(fn (?string $state): string => ActivityEventColor::for($state)),
            $withSubject
                ? TextColumn::make('subject_type')
                    ->label(__('activity.subject'))
                    ->state(fn (Activity $record): string => ActivityChanges::subjectLabel($record))
                : null,
            TextColumn::make('log_name')
                ->label(__('activity.log'))
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }
}
