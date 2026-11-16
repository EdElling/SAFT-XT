<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Auth\AuthMode;
use App\Auth\Sso\IdentityProviderType;
use App\Models\IdentityProvider;
use App\Settings\AuthSettings;

trait ConfiguresAuth
{
    protected function setAuthMode(AuthMode $mode, bool $breakGlass = true, bool $passwordReset = false): void
    {
        $settings = app(AuthSettings::class);
        $settings->mode = $mode;
        $settings->break_glass_enabled = $breakGlass;
        $settings->password_reset_enabled = $passwordReset;
        $settings->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function entraProvider(array $attributes = []): IdentityProvider
    {
        return IdentityProvider::create([
            'type' => IdentityProviderType::Entra,
            'name' => 'Firmen-Login',
            'slug' => 'firma',
            'is_enabled' => true,
            'settings' => ['tenant_id' => 'contoso.onmicrosoft.com', 'client_id' => '11111111-2222-3333-4444-555555555555'],
            'secrets' => ['client_secret' => 'super-geheim'],
            ...$attributes,
        ]);
    }

    protected function markTested(IdentityProvider $provider, bool $successful = true): IdentityProvider
    {
        $provider->forceFill(['last_test_successful' => $successful, 'last_tested_at' => now(), 'last_test_message' => 'ok'])->save();

        return $provider;
    }
}
