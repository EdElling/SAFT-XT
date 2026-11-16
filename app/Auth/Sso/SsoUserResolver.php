<?php

declare(strict_types=1);

namespace App\Auth\Sso;

use App\Auth\AuthConfiguration;
use App\Exceptions\BusinessRuleException;
use App\Models\IdentityProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ermittelt den lokalen User zu einer SSO-Identität:
 * 1. bestehende Verknüpfung, 2. Verknüpfung per E-Mail, 3. Auto-Provisionierung, sonst Ablehnung.
 * Anschließend Rollen-Abgleich gemäß Zuordnungen des IdP.
 */
final class SsoUserResolver
{
    public function __construct(
        private readonly RoleMapper $roles,
        private readonly AuthConfiguration $config,
    ) {}

    public function resolve(IdentityProvider $provider, SsoIdentity $identity): User
    {
        $this->assertDomainAllowed($provider, $identity);

        // Vor Verknüpfung/Anlage: abgelehnte Personen erzeugen kein Konto.
        $rolePlan = $this->roles->plan($provider, $identity);

        return DB::transaction(function () use ($provider, $identity, $rolePlan): User {
            $account = SocialAccount::query()
                ->where('identity_provider_id', $provider->id)
                ->where('provider_user_id', $identity->providerUserId)
                ->first();

            $user = $account !== null
                ? User::withTrashed()->find($account->user_id)
                : ($this->linkByEmail($provider, $identity) ?? $this->provision($provider, $identity));

            if ($user === null) {
                throw new BusinessRuleException(__('auth_admin.errors.not_registered'), 'sso.not_registered');
            }

            // Gelöschte Konten bleiben gesperrt, damit die Auto-Provisionierung sie nicht neu anlegt.
            if ($user->trashed()) {
                throw new BusinessRuleException(__('auth_admin.errors.removed'), 'sso.removed');
            }

            if (! $user->is_active) {
                throw new BusinessRuleException(__('auth_admin.errors.inactive'), 'sso.inactive');
            }

            $this->syncAccount($account, $provider, $identity, $user);
            $this->roles->apply($rolePlan, $user, $provider);

            return $user;
        });
    }

    private function linkByEmail(IdentityProvider $provider, SsoIdentity $identity): ?User
    {
        $email = $identity->normalizedEmail();

        if (! $provider->link_by_email || $email === null) {
            return null;
        }

        return User::withTrashed()->where('email', $email)->first();
    }

    private function provision(IdentityProvider $provider, SsoIdentity $identity): ?User
    {
        $email = $identity->normalizedEmail();

        if (! $provider->auto_provision || $email === null) {
            return null;
        }

        $user = User::create([
            'name' => $identity->name ?: $email,
            'email' => $email,
            'password' => null,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Standardrolle des IdP, sonst die globale Standardrolle (AuthSettings).
        $defaultRole = $provider->defaultRole ?? $this->config->defaultRole();

        if ($defaultRole !== null) {
            $user->assignRole($defaultRole);
        }

        return $user;
    }

    private function syncAccount(?SocialAccount $account, IdentityProvider $provider, SsoIdentity $identity, User $user): void
    {
        $account ??= new SocialAccount([
            'user_id' => $user->id,
            'identity_provider_id' => $provider->id,
            'provider_user_id' => $identity->providerUserId,
        ]);

        $account->fill([
            'provider' => $provider->type->value,
            'provider_email' => $identity->normalizedEmail(),
            'token' => $identity->token,
            'refresh_token' => $identity->refreshToken,
            'token_expires_at' => $identity->tokenExpiresAt,
        ])->save();
    }

    private function assertDomainAllowed(IdentityProvider $provider, SsoIdentity $identity): void
    {
        $allowed = $provider->allowedDomains();

        if ($allowed === []) {
            return;
        }

        if (! in_array($identity->emailDomain(), $allowed, true)) {
            throw new BusinessRuleException(__('auth_admin.errors.domain_not_allowed'), 'sso.domain_not_allowed');
        }
    }
}
