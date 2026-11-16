<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Auth\AuthConfiguration;
use App\Models\User;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/** Nur verfügbar, wenn lokale Anmeldung und Passwort-Reset aktiviert sind. */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function mount(): void
    {
        abort_unless(app(AuthConfiguration::class)->passwordResetEnabled(), 404);

        parent::mount();
    }

    public function request(): void
    {
        abort_unless(app(AuthConfiguration::class)->passwordResetEnabled(), 404);

        parent::request();
    }

    /**
     * Unbekannte Adressen und gesperrte Wiederholungen dürfen nicht verraten, ob ein Konto existiert.
     */
    protected function getFailureNotification(string $status): ?Notification
    {
        return in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)
            ? $this->getSentNotification(Password::RESET_LINK_SENT)
            : parent::getFailureNotification($status);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return ['email' => User::normalizeEmail((string) $data['email'])];
    }
}
