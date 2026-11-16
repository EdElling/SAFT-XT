<?php

declare(strict_types=1);

namespace App\Auth\Sso\Testing;

use App\Auth\Sso\IdentityProviderType;
use App\Models\IdentityProvider;

/**
 * Führt den typgerechten Verbindungstest aus und speichert das Ergebnis am IdP.
 */
final class IdentityProviderTester
{
    public function test(IdentityProvider $provider): ConnectionTestResult
    {
        $tester = match ($provider->type) {
            IdentityProviderType::Entra => app(EntraConnectionTester::class),
            IdentityProviderType::Saml => app(SamlConnectionTester::class),
        };

        $result = $tester->test($provider);

        $provider->forceFill([
            'last_tested_at' => now(),
            'last_test_successful' => $result->successful,
            'last_test_message' => $result->message,
        ])->save();

        return $result;
    }
}
