<?php

declare(strict_types=1);

namespace App\Settings;

use App\Auth\AuthMode;
use Spatie\LaravelSettings\Settings;

class AuthSettings extends Settings
{
    public AuthMode $mode;

    /** Notfall-Login (Passwort) für Super-Admins, auch im reinen SSO-Modus. */
    public bool $break_glass_enabled;

    public bool $password_reset_enabled;

    /** Rollenname für neue Benutzer (lokal vorausgewählt, SSO-Fallback). */
    public ?string $default_role;

    public static function group(): string
    {
        return 'auth';
    }
}
