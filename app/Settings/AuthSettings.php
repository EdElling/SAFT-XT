<?php

declare(strict_types=1);

namespace App\Settings;

use App\Auth\AuthMode;
use App\Auth\TwoFactorMode;
use Spatie\LaravelSettings\Settings;

class AuthSettings extends Settings
{
    public AuthMode $mode;

    /** Notfall-Login (Passwort) für Super-Admins, auch im reinen SSO-Modus. */
    public bool $break_glass_enabled;

    public bool $password_reset_enabled;

    /** Rollenname für neue Benutzer (lokal vorausgewählt, SSO-Fallback). */
    public ?string $default_role;

    // Wer die Zwei-Faktor-Anmeldung (Authenticator-App) einrichten muss. Kein Doc-Kommentar: spatie/laravel-settings
    // läse daraus den Typ und wandelte das Enum dann nicht um.
    public TwoFactorMode $two_factor;

    /** Abmelden nach so vielen Minuten ohne Bedienung; null = nie (B-162). */
    public ?int $idle_timeout_minutes;

    /** „Angemeldet bleiben“ auf der Anmeldeseite anbieten. */
    public bool $remember_me_enabled;

    /** „Angemeldet bleiben“ gilt höchstens so viele Stunden ab der Anmeldung. */
    public int $remember_me_hours;

    public static function group(): string
    {
        return 'auth';
    }
}
