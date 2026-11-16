<?php

declare(strict_types=1);

namespace App\Auth\Sso\Drivers;

use App\Auth\Sso\SsoDriver;
use App\Auth\Sso\SsoIdentity;
use App\Auth\Sso\SsoSession;
use App\Auth\Sso\SupportsSingleLogout;
use App\Auth\Sso\Testing\EntraConnectionTester;
use App\Exceptions\BusinessRuleException;
use App\Models\IdentityProvider;
use Filament\Facades\Filament;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use RuntimeException;
use SocialiteProviders\Manager\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Microsoft Entra ID über OpenID Connect / OAuth2 Authorization Code Flow mit PKCE.
 *
 * Konfiguration kommt zur Laufzeit aus dem IdentityProvider-Datensatz. Die Identität
 * wird über Microsoft Graph /me ermittelt; Benutzer-ID ist die unveränderliche Object-ID.
 * OAuth-Tokens werden bewusst nicht gespeichert (Datensparsamkeit).
 */
final class EntraDriver implements SsoDriver, SupportsSingleLogout
{
    /** Graph-Felder, die als Rohattribute an die Identität gehen (kein Token, keine Fotos). */
    private const int CLOCK_SKEW = 300;

    private const array ATTRIBUTES = ['id', 'displayName', 'mail', 'userPrincipalName', 'givenName', 'surname', 'jobTitle'];

    public function redirect(IdentityProvider $provider, Request $request): Response
    {
        return $this->provider($provider, $request)->redirect();
    }

    public function identity(IdentityProvider $provider, Request $request): SsoIdentity
    {
        if ($request->filled('error')) {
            // Fehler vom IdP (z. B. abgebrochen, fehlende Zustimmung): Details nur ins Log.
            Log::info('Entra-Anmeldung vom IdP abgelehnt', [
                'identity_provider' => $provider->slug,
                'error' => $request->string('error')->limit(100)->toString(),
                'description' => $request->string('error_description')->limit(500)->toString(),
            ]);

            throw new BusinessRuleException(
                $request->input('error') === 'access_denied'
                    ? __('auth_admin.entra.access_denied')
                    : __('auth_admin.login.sso_unavailable'),
                'sso.idp_error',
            );
        }

        $socialite = $this->provider($provider, $request);

        try {
            $user = $socialite->user();
        } catch (InvalidStateException) {
            // Abgelaufene/fremde Sitzung oder manipulierte Antwort.
            throw new BusinessRuleException(__('auth_admin.entra.invalid_state'), 'sso.invalid_state');
        }

        $raw = $user->getRaw();
        $objectId = (string) ($raw['id'] ?? '');

        if ($objectId === '') {
            throw new RuntimeException('Microsoft Graph lieferte keine Object-ID.');
        }

        return new SsoIdentity(
            providerUserId: $objectId,
            // "mail" ist die Postfachadresse; UPN als Fallback (Konten ohne Postfach).
            email: $raw['mail'] ?? $raw['userPrincipalName'] ?? null,
            name: $raw['displayName'] ?? null,
            attributes: Arr::only($raw, self::ATTRIBUTES),
            roleValues: $provider->setting('role_sync') ? $this->roleValues($provider, $socialite->lastTokenResponse()) : null,
        );
    }

    /**
     * App-Rollen ("roles") oder Gruppen-IDs ("groups") aus dem ID-Token.
     *
     * Das ID-Token stammt direkt vom Token-Endpunkt (TLS, Client-Authentifizierung);
     * laut OIDC Core 3.1.3.7 genügt dann die TLS-Prüfung statt der Signatur. Audience,
     * Tenant und Ablauf werden dennoch geprüft.
     *
     * @param  array<string, mixed>  $tokenResponse
     * @return list<string>|null null bei Gruppen-Überlauf (Werte nicht im Token)
     */
    private function roleValues(IdentityProvider $provider, array $tokenResponse): ?array
    {
        $claims = $this->decodeJwtPayload((string) ($tokenResponse['id_token'] ?? ''));

        if ($claims === null) {
            throw new RuntimeException('Entra lieferte kein auswertbares ID-Token.');
        }

        if (($claims['aud'] ?? null) !== (string) $provider->setting('client_id')) {
            throw new RuntimeException('ID-Token: Audience passt nicht zur Client-ID.');
        }

        if ((int) ($claims['exp'] ?? 0) < time() - self::CLOCK_SKEW) {
            throw new RuntimeException('ID-Token ist abgelaufen.');
        }

        // Tenant-GUID: direkt konfiguriert oder beim Verbindungstest aus der Discovery ermittelt.
        $expectedTid = $this->tenantGuid($provider);
        $tid = mb_strtolower((string) ($claims['tid'] ?? ''));

        if ($expectedTid === null) {
            throw new RuntimeException('Tenant-GUID unbekannt – bitte Verbindungstest des IdP ausführen.');
        }

        if ($tid !== $expectedTid) {
            throw new RuntimeException('ID-Token: Tenant passt nicht zur Konfiguration.');
        }

        $claim = $provider->setting('role_claim', 'roles') === 'groups' ? 'groups' : 'roles';

        if ($claim === 'groups' && (isset($claims['_claim_names']['groups']) || isset($claims['hasgroups']))) {
            Log::warning('Entra-Gruppen-Überlauf: Gruppen nicht im Token enthalten', ['identity_provider' => $provider->slug]);

            return null;
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            (array) ($claims[$claim] ?? []),
        )));
    }

    private function tenantGuid(IdentityProvider $provider): ?string
    {
        foreach ([$provider->setting('tenant_id'), $provider->setting('tenant_guid')] as $candidate) {
            if (is_string($candidate) && Str::isUuid($candidate)) {
                return mb_strtolower($candidate);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Abmeldung bei Microsoft (v2-Endpunkt). Achtung: beendet die Microsoft-Sitzung im
     * Browser für alle Microsoft-Dienste. post_logout_redirect_uri muss in der
     * App-Registrierung als Redirect-URI eingetragen sein.
     */
    public function logoutRedirect(IdentityProvider $provider, SsoSession $session, Request $request): Response
    {
        $authority = (string) ($provider->setting('authority_host') ?: EntraConnectionTester::DEFAULT_AUTHORITY);
        $tenant = rawurlencode((string) $provider->setting('tenant_id'));

        return redirect()->away("https://{$authority}/{$tenant}/oauth2/v2.0/logout?".http_build_query([
            'post_logout_redirect_uri' => Filament::getLoginUrl(),
        ]));
    }

    private function provider(IdentityProvider $provider, Request $request): EntraProvider
    {
        $clientId = (string) $provider->setting('client_id');
        $secret = $provider->secret('client_secret');

        if ($clientId === '' || $secret === null || blank($provider->setting('tenant_id'))) {
            throw new BusinessRuleException(__('auth_admin.login.sso_unavailable'), 'sso.not_configured');
        }

        $socialite = new EntraProvider($request, $clientId, $secret, $provider->callbackUrl());

        $socialite->setConfig(new Config($clientId, $secret, $provider->callbackUrl(), array_filter([
            'tenant' => (string) $provider->setting('tenant_id'),
            'authority_host' => $provider->setting('authority_host'),
            'graph_url' => $provider->setting('graph_url'),
        ])));

        // Laravels Handler-Stack: Timeouts, Http::fake() in Tests, einheitliches Verhalten.
        $socialite->setHttpClient(new Client([
            'handler' => Http::buildHandlerStack(),
            'timeout' => 10,
            'connect_timeout' => 5,
        ]));

        return $socialite
            ->scopes(['openid', 'profile', 'email'])
            ->enablePKCE();
    }
}
