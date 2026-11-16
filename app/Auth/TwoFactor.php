<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Sso\SsoSession;
use App\Models\User;
use App\Settings\AuthSettings;

/**
 * Zwei-Faktor-Anmeldung (Authenticator-App, B-057): wer sie einrichten muss und das Zurücksetzen durch die
 * Administration. Anmeldung und Einrichtung selbst übernimmt Filament (AppAuthentication).
 */
final class TwoFactor
{
    /** Bereiche, deren Rechte als Administration gelten (ein beliebiges Recht genügt). */
    public const array ADMINISTRATION = ['users', 'roles', 'menus', 'identity_providers', 'settings.auth', 'settings.app', 'custom_tables', 'database_connections', 'table_connector', 'report_links', 'tasks', 'plugins', 'configuration'];

    public function __construct(
        private readonly AuthSettings $settings,
        private readonly RoleSimulation $simulation,
    ) {}

    public function mode(): TwoFactorMode
    {
        return $this->settings->two_factor;
    }

    /** Ein Konto ohne Passwort meldet sich nur per SSO an; den zweiten Faktor verlangt dann der Identity Provider. */
    public function isAvailableFor(User $user): bool
    {
        return $user->getAuthPassword() !== null && $user->getAuthPassword() !== '';
    }

    public function isSetUp(User $user): bool
    {
        return filled($user->getAppAuthenticationSecret());
    }

    /**
     * Muss diese Person in der laufenden Sitzung einen zweiten Faktor eingerichtet haben? Nicht nach einer Anmeldung
     * per SSO und nicht während „Ansicht als Rolle“ (die Person war vorher schon angemeldet und geprüft).
     */
    public function isRequiredFor(User $user): bool
    {
        if (! (bool) config('saft.two_factor_enforce') || ! $this->isAvailableFor($user)) {
            return false;
        }

        if (request()->hasSession() && request()->session()->has(SsoSession::SESSION_KEY)) {
            return false;
        }

        if ($this->simulation->isActive()) {
            return false;
        }

        return match ($this->mode()) {
            TwoFactorMode::Optional => false,
            TwoFactorMode::Everyone => true,
            TwoFactorMode::Administration => $this->administers($user),
        };
    }

    /** Super-Admin oder mindestens ein Recht in einem Bereich der Administration. */
    public function administers(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach ($user->getAllPermissions()->pluck('name') as $permission) {
            foreach (self::ADMINISTRATION as $resource) {
                if (str_starts_with((string) $permission, $resource.'.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Für verlorene Geräte: Geheimnis und Wiederherstellungscodes löschen; die Person richtet neu ein. */
    public function reset(User $user, ?User $by = null): void
    {
        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->saveQuietly();

        activity('auth')->performedOn($user)->causedBy($by)->event('two_factor_reset')->log('two_factor_reset');
    }
}
