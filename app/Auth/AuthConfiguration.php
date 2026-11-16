<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\User;
use App\Settings\AuthSettings;
use Illuminate\Database\Eloquent\Collection;

/**
 * Zentrale Auskunft über die wirksame Authentifizierungs-Konfiguration.
 *
 * AUTH_FORCE_LOCAL (config auth.force_local) übersteuert die Datenbank – Notausgang,
 * falls ein fehlkonfigurierter IdP alle aussperrt.
 */
final class AuthConfiguration
{
    public function __construct(private readonly AuthSettings $settings) {}

    public function mode(): AuthMode
    {
        return $this->isForcedLocal() ? AuthMode::Local : $this->settings->mode;
    }

    public function isForcedLocal(): bool
    {
        return (bool) config('auth.force_local', false);
    }

    public function allowsLocalLogin(): bool
    {
        return $this->mode()->allowsLocal();
    }

    public function breakGlassAvailable(): bool
    {
        return ! $this->allowsLocalLogin() && $this->settings->break_glass_enabled;
    }

    /**
     * Darf sich dieser User per Passwort anmelden?
     */
    public function mayUsePassword(User $user, bool $breakGlass = false): bool
    {
        if ($this->allowsLocalLogin()) {
            return true;
        }

        return $breakGlass && $this->breakGlassAvailable() && $user->isSuperAdmin();
    }

    public function passwordResetEnabled(): bool
    {
        return $this->allowsLocalLogin() && $this->settings->password_reset_enabled;
    }

    /**
     * @return Collection<int, IdentityProvider>
     */
    public function loginProviders(): Collection
    {
        if (! $this->mode()->allowsSso()) {
            return new Collection;
        }

        return IdentityProvider::query()->enabled()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Voraussetzung für Modus "sso": mindestens ein aktiver, erfolgreich getesteter IdP.
     */
    public function hasVerifiedProvider(): bool
    {
        return IdentityProvider::query()->enabled()->where('last_test_successful', true)->exists();
    }

    /** Konfigurierte Standardrolle (nie super-admin) oder null. */
    public function defaultRole(): ?Role
    {
        $name = $this->settings->default_role;

        if (blank($name) || $name === User::ROLE_SUPER_ADMIN) {
            return null;
        }

        return Role::query()->where('name', $name)->where('guard_name', 'web')->first();
    }

    public function settings(): AuthSettings
    {
        return $this->settings;
    }
}
