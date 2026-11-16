<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Auth\AuthConfiguration;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use SensitiveParameter;

/** Nur verfügbar, wenn lokale Anmeldung und Passwort-Reset aktiviert sind. */
class ResetPassword extends BaseResetPassword
{
    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        abort_unless(app(AuthConfiguration::class)->passwordResetEnabled(), 404);

        parent::mount($email, $token);
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        abort_unless(app(AuthConfiguration::class)->passwordResetEnabled(), 404);

        return parent::resetPassword();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [...$data, 'email' => User::normalizeEmail((string) $data['email'])];
    }
}
