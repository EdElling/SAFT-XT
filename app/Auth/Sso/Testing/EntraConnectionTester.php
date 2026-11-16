<?php

declare(strict_types=1);

namespace App\Auth\Sso\Testing;

use App\Models\IdentityProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Prüft Tenant (OIDC-Discovery) und – falls Secret vorhanden – Client-ID/Secret per
 * Client-Credentials-Grant. Benötigt keine API-Berechtigungen der App-Registrierung.
 */
final class EntraConnectionTester implements ConnectionTester
{
    public const string DEFAULT_AUTHORITY = 'login.microsoftonline.com';

    public function test(IdentityProvider $provider): ConnectionTestResult
    {
        $tenant = rawurlencode((string) $provider->setting('tenant_id'));
        $authority = (string) ($provider->setting('authority_host') ?: self::DEFAULT_AUTHORITY);

        try {
            $discovery = Http::timeout(10)->acceptJson()
                ->get("https://{$authority}/{$tenant}/v2.0/.well-known/openid-configuration");

            if (! $discovery->successful() || ! is_string($discovery->json('token_endpoint'))) {
                return ConnectionTestResult::failed(__('auth_admin.test.http_error', ['status' => $discovery->status()]));
            }

            $issuer = (string) $discovery->json('issuer');
            $this->rememberTenantGuid($provider, $issuer);
            $secret = $provider->secret('client_secret');

            if ($secret === null) {
                return ConnectionTestResult::failed(__('auth_admin.test.entra_discovery_ok', ['issuer' => $issuer]));
            }

            $token = Http::timeout(10)->asForm()->acceptJson()->post((string) $discovery->json('token_endpoint'), [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $provider->setting('client_id'),
                'client_secret' => $secret,
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

            if ($token->successful() && is_string($token->json('access_token'))) {
                return ConnectionTestResult::ok(__('auth_admin.test.entra_ok', ['issuer' => $issuer]));
            }

            // Erste Zeile der AADSTS-Meldung genügt (ohne Trace-/Correlation-IDs).
            $reason = Str::before((string) ($token->json('error_description') ?? $token->json('error') ?? $token->status()), "\r\n");

            return ConnectionTestResult::failed(__('auth_admin.test.entra_credentials_invalid', ['message' => $reason]));
        } catch (ConnectionException $e) {
            return ConnectionTestResult::failed(__('auth_admin.test.unreachable', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Issuer der Discovery enthält die Tenant-GUID (".../{guid}/v2.0"). Sie wird gemerkt,
     * damit der tid-Claim auch bei Konfiguration per Domain geprüft werden kann.
     * Gespeichert wird zusammen mit dem Testergebnis (IdentityProviderTester).
     */
    private function rememberTenantGuid(IdentityProvider $provider, string $issuer): void
    {
        if (preg_match('#/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/#i', $issuer, $m) === 1) {
            $provider->settings = [...($provider->settings ?? []), 'tenant_guid' => mb_strtolower($m[1])];
        }
    }
}
