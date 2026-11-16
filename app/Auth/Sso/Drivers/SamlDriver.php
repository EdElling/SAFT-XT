<?php

declare(strict_types=1);

namespace App\Auth\Sso\Drivers;

use App\Auth\Sso\ProvidesMetadata;
use App\Auth\Sso\Saml\Certificates;
use App\Auth\Sso\Saml\SamlLogoutMessage;
use App\Auth\Sso\SsoDriver;
use App\Auth\Sso\SsoIdentity;
use App\Auth\Sso\SsoSession;
use App\Auth\Sso\SupportsSingleLogout;
use App\Exceptions\BusinessRuleException;
use App\Models\IdentityProvider;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use LightSaml\Model\Assertion\Assertion;
use LightSaml\Model\Assertion\AttributeStatement;
use LightSaml\Model\Protocol\LogoutRequest;
use LightSaml\SamlConstants;
use RuntimeException;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Saml2\User as Saml2User;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAML 2.0 (SP-initiiert, HTTP-Redirect zum IdP, HTTP-POST zurück an den ACS)
 * inkl. Single Logout (HTTP-Redirect in beide Richtungen).
 *
 * Konfiguration zur Laufzeit aus dem IdentityProvider-Datensatz. Benutzer-ID ist die
 * NameID; E-Mail und Name kommen aus den konfigurierten Attributen.
 */
final class SamlDriver implements ProvidesMetadata, SsoDriver, SupportsSingleLogout
{
    public const string LOGOUT_SESSION_KEY = 'saml_logout';

    public function redirect(IdentityProvider $provider, Request $request): Response
    {
        return $this->provider($provider, $request)->redirect();
    }

    public function identity(IdentityProvider $provider, Request $request): SsoIdentity
    {
        try {
            /** @var Saml2User $user */
            $user = $this->provider($provider, $request)->user();
        } catch (InvalidStateException) {
            // Keine passende Anfrage in dieser Sitzung (abgelaufen, fremd oder IdP-initiiert).
            throw new BusinessRuleException(__('auth_admin.saml.invalid_state'), 'sso.invalid_state');
        }

        $nameId = (string) $user->getId();

        if ($nameId === '') {
            throw new RuntimeException('Die SAML-Assertion enthält keine NameID.');
        }

        // Für Single Logout: NameID-Format und SessionIndex der IdP-Sitzung.
        $assertion = $user->getAssertion();

        return new SsoIdentity(
            providerUserId: $nameId,
            email: $this->stringOrNull($user->getEmail()),
            name: $this->stringOrNull($user->getName()),
            attributes: array_filter([
                'first_name' => $this->stringOrNull($user->getFirstName()),
                'last_name' => $this->stringOrNull($user->getLastName()),
                'upn' => $this->stringOrNull($user->getUpn()),
                'name_id_format' => $assertion->getSubject()?->getNameID()?->getFormat(),
                'session_index' => $assertion->getFirstAuthnStatement()?->getSessionIndex(),
            ]),
            roleValues: $provider->setting('role_sync') ? $this->roleValues($provider, $assertion) : null,
        );
    }

    public function logoutRedirect(IdentityProvider $provider, SsoSession $session, Request $request): Response
    {
        $relayState = Str::random(32);

        [$response, $requestId] = $this->provider($provider, $request)
            ->singleLogoutRequest($session->nameId, $session->nameIdFormat, $session->sessionIndex, $relayState);

        // In der (nach dem Logout neuen) Session, um die Antwort des IdP zuzuordnen.
        $request->session()->put(self::LOGOUT_SESSION_KEY, [
            'identity_provider_id' => $provider->id,
            'request_id' => $requestId,
            'relay_state' => $relayState,
        ]);

        return $response;
    }

    /** Empfängt eine (geprüfte) Logout-Nachricht des IdP am SLS-Endpunkt. */
    public function receiveLogout(IdentityProvider $provider, Request $request): SamlLogoutMessage
    {
        $saml = $this->provider($provider, $request);
        $message = $saml->receiveLogoutMessage();

        if ($message instanceof LogoutRequest) {
            return SamlLogoutMessage::request(
                (string) $message->getNameID()->getValue(),
                static fn (): Response => $saml->logoutResponseTo($message),
            );
        }

        return SamlLogoutMessage::response($message->getInResponseTo(), (bool) $message->getStatus()?->isSuccess());
    }

    public function metadata(IdentityProvider $provider): Response
    {
        return $this->provider($provider, request())->getServiceProviderMetadata();
    }

    /** Verwirft zwischengespeicherte IdP-Metadaten (nach Zertifikatswechsel beim IdP). */
    public function clearMetadataCache(IdentityProvider $provider): void
    {
        $this->provider($provider, request())->clearIdentityProviderMetadataCache();
    }

    private function provider(IdentityProvider $provider, Request $request): SamlProvider
    {
        $saml = new SamlProvider($request);
        $saml->setConfig(new Config('', '', '', $this->config($provider)));

        // Laravels Handler-Stack für den Metadaten-Abruf: Timeouts, Http::fake() in Tests.
        $saml->setHttpClient(new Client([
            'handler' => Http::buildHandlerStack(),
            'timeout' => 10,
            'connect_timeout' => 5,
        ]));

        return $saml;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(IdentityProvider $provider): array
    {
        $config = [
            // Relativer Pfad: das Paket ermittelt Binding und absolute URL über das Routing.
            'sp_acs' => Str::after($provider->acsUrl(), rtrim(url('/'), '/').'/'),
            // SLS nur als GET-Route -> Metadaten bieten ausschließlich HTTP-Redirect an.
            'sp_sls' => Str::after($provider->slsUrl(), rtrim(url('/'), '/').'/'),
            'sp_entityid' => $provider->spEntityId(),
            'sp_name_id_format' => $provider->setting('name_id_format', SamlConstants::NAME_ID_FORMAT_PERSISTENT),
            'sp_default_binding_method' => SamlConstants::BINDING_SAML2_HTTP_POST,
            'sp_sign_assertions' => true,
            'idp_binding_method' => SamlConstants::BINDING_SAML2_HTTP_REDIRECT,
            'attribute_map' => [
                'email' => array_values(array_filter([$provider->setting('attribute_email'), ...SamlProvider::ATTRIBUTE_MAP['email']])),
                'name' => array_values(array_filter([$provider->setting('attribute_name'), ...SamlProvider::ATTRIBUTE_MAP['name']])),
            ],
        ];

        if (filled($provider->setting('idp_metadata_url'))) {
            $config['metadata'] = (string) $provider->setting('idp_metadata_url');
            $config['entityid'] = $provider->setting('idp_entity_id');
        } else {
            // Achtung Paket-Nomenklatur: "acs" meint hier die SSO-URL des IdP.
            $config['acs'] = $provider->setting('idp_sso_url');
            $config['entityid'] = $provider->setting('idp_entity_id');
            $config['slo'] = $provider->setting('idp_slo_url');
            $config['certificate'] = filled($provider->setting('idp_certificate'))
                ? Certificates::toPem((string) $provider->setting('idp_certificate'))
                : null;
        }

        if ($provider->setting('sign_requests')) {
            $config['sp_certificate'] = $provider->setting('sp_certificate');
            $config['sp_private_key'] = $provider->secret('sp_private_key');
        }

        return array_filter($config, static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * Alle Werte des konfigurierten Rollen-Attributs (mehrwertig). Fehlt das Attribut,
     * hat die Person keine Gruppen -> leere Liste (verwaltete Rollen werden entzogen).
     *
     * @return list<string>
     */
    private function roleValues(IdentityProvider $provider, Assertion $assertion): array
    {
        $name = (string) $provider->setting('role_attribute');

        if ($name === '') {
            return [];
        }

        $values = [];

        foreach ($assertion->getAllItems() as $statement) {
            if (! $statement instanceof AttributeStatement) {
                continue;
            }

            foreach ($statement->getAllAttributes() as $attribute) {
                if ($attribute->getName() === $name || $attribute->getFriendlyName() === $name) {
                    array_push($values, ...array_map('strval', $attribute->getAllAttributeValues() ?? []));
                }
            }
        }

        return array_values(array_filter($values, static fn (string $v): bool => trim($v) !== ''));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
