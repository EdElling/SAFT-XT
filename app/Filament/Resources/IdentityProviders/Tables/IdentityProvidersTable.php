<?php

declare(strict_types=1);

namespace App\Filament\Resources\IdentityProviders\Tables;

use App\Auth\Sso\IdentityProviderType;
use App\Filament\Resources\IdentityProviders\Actions\TestConnectionAction;
use App\Models\IdentityProvider;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IdentityProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('common.type'))
                    ->badge()
                    ->formatStateUsing(fn (IdentityProviderType $state): string => $state->label()),
                TextColumn::make('slug')
                    ->label(__('idp.table.slug'))
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_enabled')
                    ->label(__('common.active'))
                    ->boolean(),
                IconColumn::make('last_test_successful')
                    ->label(__('idp.table.test'))
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
                    ->tooltip(fn (IdentityProvider $record): ?string => $record->last_test_message),
                TextColumn::make('social_accounts_count')
                    ->label(__('idp.table.accounts'))
                    ->counts('social_accounts')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('sort_order')
                    ->label(__('common.sort_order'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                TestConnectionAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('idp.table.delete_help')),
            ]);
    }
}
