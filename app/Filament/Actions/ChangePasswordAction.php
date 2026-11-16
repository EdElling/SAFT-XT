<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Auth\AuthConfiguration;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * „Passwort ändern“ im Benutzermenü: aktuelles Passwort bestätigen, neues zweimal eingeben. Nicht für
 * Konten mit aktiver SSO-Verbindung, ohne lokales Passwort oder wenn die lokale Anmeldung abgeschaltet ist.
 */
final class ChangePasswordAction
{
    public static function make(): Action
    {
        return Action::make('changePassword')
            ->label(fn (): string => __('password_change.menu'))
            ->icon('heroicon-o-key')
            ->visible(fn (): bool => self::available(self::user()))
            ->modalHeading(fn (): string => __('password_change.title'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(fn (): string => __('password_change.submit'))
            // Erst beim Öffnen bauen: Die Aktion entsteht schon in der Panel-Konfiguration, bevor Filament seine
            // Übersetzungen registriert hat – Eingabefelder läsen dann leere Texte, die im Speicher blieben.
            ->schema(fn (): array => [
                TextInput::make('current_password')
                    ->label(__('password_change.current'))
                    ->password()
                    ->revealable()
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword(),
                TextInput::make('password')
                    ->label(__('password_change.new'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->rule(Password::defaults())
                    ->different('current_password')
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label(__('password_change.confirm'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $user = self::user();
                abort_unless(self::available($user), 403);

                $user->forceFill(['password' => $data['password']])->save();

                // Die Sitzung bleibt gültig (AuthenticateSession vergleicht den Passwort-Hash).
                if (request()->hasSession()) {
                    request()->session()->put('password_hash_'.Filament::getAuthGuard(), $user->getAuthPassword());
                }

                activity('auth')->performedOn($user)->causedBy($user)->event('password_changed')->log('password_changed');
                Notification::make()->success()->title(__('password_change.done'))->send();
            });
    }

    public static function available(?User $user): bool
    {
        if ($user === null || blank($user->password) || ! app(AuthConfiguration::class)->allowsLocalLogin()) {
            return false;
        }

        return ! $user->socialAccounts()
            ->whereHas('identity_provider', fn ($q) => $q->where('is_enabled', true))
            ->exists();
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
