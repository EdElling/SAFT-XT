<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\Tables;

use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Plugins\PluginDependencies;
use App\Support\AppLocale;
use Filament\Actions\EditAction;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class CustomTablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label(__('tables.form.display_name'))
                    // Puzzle-Symbol wie in der Feldliste: ein installiertes Plugin braucht die Tabelle.
                    ->icon(fn (CustomTable $record): ?string => app(PluginDependencies::class)->hint($record->name) !== null ? 'heroicon-o-puzzle-piece' : null)
                    ->iconPosition(IconPosition::After)
                    ->tooltip(fn (CustomTable $record): ?string => app(PluginDependencies::class)->hint($record->name))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        foreach (AppLocale::available() as $locale) {
                            $q->orWhereLike("label->{$locale}", "%{$search}%");
                        }
                    }))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('label->'.app()->getLocale(), $direction)->orderBy('label->'.config('locales.default'), $direction)),
                TextColumn::make('name')->label(__('tables.list.name'))->fontFamily('mono')->searchable(),
                TextColumn::make('fields_count')->label(__('tables.fields.title'))->counts('fields')->numeric()->alignEnd(),
                TextColumn::make('records')
                    ->label(__('common.records'))
                    ->alignEnd()
                    ->state(function (CustomTable $record): string {
                        try {
                            return (string) DynamicRecord::forTable($record)->newQuery()->count();
                        } catch (Throwable) {
                            return '—';
                        }
                    }),
                IconColumn::make('is_active')->label(__('common.active'))->boolean(),
                TextColumn::make('updated_at')->label(__('common.changed'))->since()->dateTimeTooltip()->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('label->'.app()->getLocale())->orderBy('label->'.config('locales.default')))
            ->filters([
                TernaryFilter::make('is_active')->label(__('common.active')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
