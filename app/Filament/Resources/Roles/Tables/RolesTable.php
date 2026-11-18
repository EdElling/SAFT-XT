<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use App\Filament\Resources\Roles\Actions\CopyRoleAction;
use App\Filament\Support\AppFilter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('common.name'))
                    ->searchable()
                    ->sortable(),
                AppFilter::column(),
                TextColumn::make('permissions_count')
                    ->label(__('common.permissions'))
                    ->counts('permissions')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label(__('common.users'))
                    ->counts('users')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('common.changed'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([AppFilter::make(system: true)])
            ->recordActions([
                EditAction::make(),
                CopyRoleAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
