<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Support\RowIcons;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('common.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label(__('common.roles'))
                    ->badge(),
                IconColumn::make('is_active')
                    ->label(__('common.active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label(__('users.table.last_login'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('common.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('common.active')),
                SelectFilter::make('roles')
                    ->label(__('common.role'))
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                Filter::make('without_roles')
                    ->label(__('users.table.without_role'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('roles')),
                TernaryFilter::make('sso_linked')
                    ->label(__('users.table.sso_linked'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('social_accounts'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('social_accounts'),
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions(RowIcons::of([
                EditAction::make(),
                DeleteAction::make()->modalDescription(fn (): string => __('users.delete.soft')),
                RestoreAction::make(),
                // Nur bei gelöschten Konten sichtbar (Filament), neben „Wiederherstellen“.
                ForceDeleteAction::make()->modalDescription(fn (): string => __('users.delete.force')),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pro Datensatz autorisieren: verhindert z. B. Selbst-Löschung per Bulk.
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete')
                        ->modalDescription(fn (): string => __('users.delete.soft')),
                    ForceDeleteBulkAction::make()->authorizeIndividualRecords('forceDelete')
                        ->modalDescription(fn (): string => __('users.delete.force')),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ]);
    }
}
