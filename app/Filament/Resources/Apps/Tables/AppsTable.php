<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Tables;

use App\Models\App;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AppsTable
{
    public static function configure(Table $table): Table
    {
        // Spaltenname wie das Zählattribut von withCount: custom_tables_count für customTables.
        $count = static fn (string $relation, string $label): TextColumn => TextColumn::make(Str::snake($relation).'_count')
            ->label(__('apps.parts.'.$label))
            ->counts($relation)
            ->alignment(Alignment::End)
            ->sortable();

        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('apps.name'))
                    ->icon(fn (App $record): ?string => $record->icon)
                    ->description(fn (App $record): ?string => filled($record->description) ? (string) $record->description : null)
                    ->wrap(),
                TextColumn::make('prefix')->label(__('apps.prefix'))->placeholder('–'),
                ...array_map(static fn (string $relation, string $label): TextColumn => $count($relation, $label), array_keys(App::PARTS), App::PARTS),
                TextColumn::make('sort_order')->label(__('common.sort_order'))->alignment(Alignment::End)->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (App $record): bool => ! $record->isEmpty())
                    ->tooltip(fn (App $record): ?string => $record->isEmpty() ? null : __('apps.delete_only_empty')),
            ]);
    }
}
