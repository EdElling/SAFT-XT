<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Tables;

use App\Filament\Support\AppFilter;
use App\Models\Menu;
use App\Navigation\MenuTree;
use App\Support\AppLocale;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MenusTable
{
    private const string ROOT = 'root';

    public static function configure(Table $table): Table
    {
        // Einmal pro Request: Pfade für alle Zeilen ohne N+1.
        $tree = static fn (): MenuTree => once(static fn (): MenuTree => MenuTree::fromDatabase());

        return $table
            ->columns([
                TextColumn::make('label')
                    ->label(__('common.label'))
                    // Die Spalte ist JSON: in jeder Sprache suchen.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search): void {
                        foreach (AppLocale::available() as $locale) {
                            $q->orWhereLike("label->{$locale}", "%{$search}%");
                        }
                    }))
                    ->description(function (Menu $record) use ($tree): ?string {
                        $path = $tree()->path($record->id);
                        array_pop($path);

                        return $path === [] ? null : implode(' › ', $path).' ›';
                    }, position: 'above'),
                TextColumn::make('target')
                    ->label(__('menus.table.target'))
                    ->state(fn (Menu $record): string => $record->route ?? $record->url ?? __('menus.types.group'))
                    ->color(fn (Menu $record): ?string => $record->route === null && $record->url === null ? 'gray' : null),
                TextColumn::make('permission_name')
                    ->label(__('menus.table.permission'))
                    ->badge()
                    ->placeholder(__('menus.table.all')),
                TextColumn::make('sort_order')
                    ->label(__('common.sort_order'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('common.active'))
                    ->boolean(),
                AppFilter::column(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                AppFilter::make(system: true),
                SelectFilter::make('parent_id')
                    ->label(__('menus.parent'))
                    ->options(fn (): array => [self::ROOT => __('menus.root')] + $tree()->parentOptions())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        self::ROOT => $query->whereNull('parent_id'),
                        default => $query->where('parent_id', (int) $data['value']),
                    }),
                TernaryFilter::make('is_active')
                    ->label(__('common.active')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('menus.delete_children')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
