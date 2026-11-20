<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Tables;

use App\ExternalData\ConnectionDriver;
use App\Filament\Resources\DatabaseConnections\Actions\DeleteDatabaseConnectionAction;
use App\Filament\Resources\DatabaseConnections\Actions\TestDatabaseConnectionAction;
use App\Filament\Support\RowIcons;
use App\Models\DatabaseConnection;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DatabaseConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('driver')
                    ->label(__('db_connections.form.driver'))
                    ->badge()
                    ->formatStateUsing(fn (ConnectionDriver $state): string => $state->label()),
                TextColumn::make('host')
                    ->label(__('db_connections.table.server'))
                    ->formatStateUsing(fn (DatabaseConnection $record): string => "{$record->host}:{$record->port}/{$record->database}")
                    ->fontFamily('mono')
                    ->searchable(['host', 'database']),
                TextColumn::make('default_schema')
                    ->label(__('db_connections.form.schema'))
                    ->getStateUsing(fn (DatabaseConnection $record): string => $record->schemaName()),
                IconColumn::make('is_read_only')
                    ->label(__('db_connections.form.read_only'))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label(__('common.active'))
                    ->boolean(),
                IconColumn::make('last_test_successful')
                    ->label(__('db_connections.table.test'))
                    ->icon(fn (?bool $state): string => match ($state) {
                        true => 'heroicon-o-check-circle',
                        false => 'heroicon-o-x-circle',
                        null => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn (?bool $state): string => match ($state) {
                        true => 'success',
                        false => 'danger',
                        null => 'gray',
                    })
                    ->tooltip(fn (DatabaseConnection $record): ?string => $record->last_test_message),
            ])
            ->defaultSort('name')
            ->recordActions(RowIcons::of([
                TestDatabaseConnectionAction::make(),
                EditAction::make(),
                DeleteDatabaseConnectionAction::make(),
            ]));
    }
}
