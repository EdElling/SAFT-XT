<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Support\RowIcons;
use App\Models\SocialAccount;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Verknüpfte SSO-Identitäten eines Benutzers (nur ansehen und trennen).
 */
class SocialAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'social_accounts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('users.sso.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('identity_provider'))
            ->columns([
                TextColumn::make('identity_provider.name')
                    ->label(__('users.sso.provider'))
                    ->placeholder('—'),
                TextColumn::make('provider_email')
                    ->label(__('users.sso.provider_email')),
                TextColumn::make('provider_user_id')
                    ->label(__('users.sso.provider_user_id'))
                    ->fontFamily('mono')
                    ->limit(24)
                    ->tooltip(fn (SocialAccount $record): string => $record->provider_user_id),
                TextColumn::make('created_at')
                    ->label(__('users.sso.linked_since'))
                    ->dateTime(),
            ])
            ->recordActions(RowIcons::of([
                DeleteAction::make()
                    ->label(__('users.sso.unlink'))
                    ->modalHeading(__('users.sso.unlink_heading'))
                    ->modalDescription(__('users.sso.unlink_description')),
            ]));
    }
}
