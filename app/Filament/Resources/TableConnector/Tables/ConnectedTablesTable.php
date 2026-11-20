<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\Tables;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\SchemaComparison;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\TableConnector\Actions\AssignConnectionAction;
use App\Filament\Support\AppFilter;
use App\Models\CustomTable;
use App\Support\AppLocale;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ConnectedTablesTable
{
    /** @var array<int, true> Verbindungen, die in dieser Anfrage nicht erreichbar waren */
    private static array $unreachable = [];

    /** „ok“, „error“ (nicht lesbar) oder die Zahl der Abweichungen. */
    private static function schemaCheck(CustomTable $record): string
    {
        $connection = (int) $record->database_connection_id;

        if ($connection === 0) {
            return 'no_connection';
        }

        if (isset(self::$unreachable[$connection]) || ! $record->databaseConnection?->is_active) {
            return 'error';
        }

        try {
            $count = app(SchemaComparison::class)->compare($record)->count();

            return $count === 0 ? 'ok' : (string) $count;
        } catch (BusinessRuleException) {
            return 'error';
        } catch (Throwable) {
            // Nicht für jede Tabelle derselben Verbindung erneut auf das Zeitlimit warten.
            self::$unreachable[$connection] = true;

            return 'error';
        }
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['databaseConnection', 'app'])->withCount('fields'))
            ->columns([
                TextColumn::make('label')->label(__('tables.form.display_name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        foreach (AppLocale::available() as $locale) {
                            $q->orWhereLike("label->{$locale}", "%{$search}%");
                        }
                    })),
                TextColumn::make('name')->label(__('tables.list.name'))->fontFamily('mono')->searchable(),
                AppFilter::column(),
                TextColumn::make('databaseConnection.name')->label(__('table_connector.form.connection'))->sortable()
                    // Ohne Verbindung (z. B. aus einer installierten App): deutlich kennzeichnen.
                    ->placeholder(__('table_connector.assign.missing'))
                    ->badge(fn (CustomTable $record): bool => $record->database_connection_id === null)
                    ->color(fn (CustomTable $record): ?string => $record->database_connection_id === null ? 'warning' : null),
                TextColumn::make('external_table')
                    ->label(__('table_connector.list.source'))
                    ->formatStateUsing(fn (CustomTable $record): string => $record->external_schema.'.'.$record->external_table)
                    ->fontFamily('mono')
                    ->sortable(['external_schema', 'external_table']),
                TextColumn::make('external_kind')
                    ->label(__('table_connector.list.kind'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __('table_connector.kinds.'.($state ?? 'table')))
                    ->sortable(),
                TextColumn::make('schema_check')
                    ->label(__('table_connector.list.schema_check'))
                    ->badge()
                    ->state(fn (CustomTable $record): string => self::schemaCheck($record))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ok' => __('table_connector.list.schema_ok'),
                        'error' => __('table_connector.list.schema_error'),
                        'no_connection' => __('table_connector.assign.missing'),
                        default => trans_choice('table_connector.list.schema_changes', (int) $state, ['count' => $state]),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'error', 'no_connection' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('fields_count')->label(__('table_connector.columns.title'))->numeric()->alignEnd(),
                IconColumn::make('is_active')->label(__('common.active'))->boolean(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('label->'.app()->getLocale())->orderBy('label->'.config('locales.default')))
            // „Abgleich“ wird je Zeile live in der externen Datenbank ermittelt und ist daher nicht filterbar.
            ->filters([
                SelectFilter::make('database_connection_id')
                    ->label(__('table_connector.form.connection'))
                    ->relationship('databaseConnection', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('external_schema')
                    ->label(__('table_connector.form.schema'))
                    ->options(fn (): array => CustomTable::query()->external()->whereNotNull('external_schema')->distinct()->orderBy('external_schema')->pluck('external_schema', 'external_schema')->all())
                    ->multiple(),
                SelectFilter::make('external_kind')
                    ->label(__('table_connector.list.kind'))
                    ->options(['table' => __('table_connector.kinds.table'), 'view' => __('table_connector.kinds.view')]),
                TernaryFilter::make('is_active')->label(__('common.active')),
                TernaryFilter::make('export_enabled')->label(__('tables.form.export_enabled')),
                TernaryFilter::make('writable')
                    ->label(__('table_connector.form.write'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('allow_create', true)->orWhere('allow_update', true)->orWhere('allow_delete', true)),
                        false: fn (Builder $query): Builder => $query->where('allow_create', false)->where('allow_update', false)->where('allow_delete', false),
                    ),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                Action::make('openData')
                    ->label(__('tables.actions.open_data'))
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->url(fn (CustomTable $record): string => DynamicDataPage::urlFor($record->name))
                    ->visible(fn (CustomTable $record): bool => $record->is_active),
                AssignConnectionAction::make(),
                EditAction::make(),
            ]);
    }
}
