<?php

declare(strict_types=1);

namespace App\Filament\Resources\IdentityProviders\Actions;

use App\Auth\Sso\Testing\IdentityProviderTester;
use App\Models\IdentityProvider;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Testet die gespeicherte Konfiguration eines Identity Providers.
 */
class TestConnectionAction
{
    public static function make(?\Closure $before = null): Action
    {
        return Action::make('testConnection')
            ->label(__('idp.test.run'))
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->authorize(fn (IdentityProvider $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (IdentityProvider $record) use ($before): void {
                if ($before !== null) {
                    $before();
                }

                $result = app(IdentityProviderTester::class)->test($record->refresh());

                Notification::make()
                    ->title(__($result->successful ? 'auth_admin.test.success' : 'auth_admin.test.failure'))
                    ->body($result->message)
                    ->{$result->successful ? 'success' : 'danger'}()
                    ->persistent()
                    ->send();
            });
    }
}
