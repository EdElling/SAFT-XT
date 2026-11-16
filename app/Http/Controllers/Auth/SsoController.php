<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\AuthConfiguration;
use App\Auth\Sso\Drivers\SamlDriver;
use App\Auth\Sso\ProvidesMetadata;
use App\Auth\Sso\SsoDriver;
use App\Auth\Sso\SsoDriverRegistry;
use App\Auth\Sso\SsoIdentity;
use App\Auth\Sso\SsoSession;
use App\Auth\Sso\SsoUserResolver;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\IdentityProvider;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SsoController extends Controller
{
    private const string ACS_CACHE_PREFIX = 'saml_acs_handover:';

    private const int ACS_HANDOVER_TTL = 300;

    private const int MAX_SAML_RESPONSE_LENGTH = 1_048_576;

    public function __construct(
        private readonly AuthConfiguration $config,
        private readonly SsoDriverRegistry $drivers,
        private readonly SsoUserResolver $resolver,
    ) {}

    public function redirect(Request $request, IdentityProvider $identityProvider): Response
    {
        $driver = $this->driverOrNull($identityProvider);

        if ($driver === null) {
            return $this->backToLogin(__('auth_admin.login.sso_unavailable'));
        }

        try {
            return $driver->redirect($identityProvider, $request);
        } catch (BusinessRuleException $e) {
            return $this->backToLogin($e->getMessage());
        }
    }

    /** OIDC-Callback (Entra). */
    public function callback(Request $request, IdentityProvider $identityProvider): Response
    {
        return $this->complete($identityProvider, $request, $request);
    }

    /**
     * SAML-ACS, Stufe 1: Cross-Site-POST vom IdP – ohne Session (siehe Route).
     * Die Antwort wird einmalig zwischengespeichert und per 303 an Stufe 2 übergeben,
     * wo der Browser das (SameSite=Lax) Session-Cookie wieder mitsendet.
     */
    public function samlAcs(Request $request, IdentityProvider $identityProvider): RedirectResponse
    {
        // Kein $request->validate(): ohne Session kann nicht per Flash zurückgeleitet werden.
        $validator = Validator::make($request->only(['SAMLResponse', 'RelayState']), [
            'SAMLResponse' => ['required', 'string', 'max:'.self::MAX_SAML_RESPONSE_LENGTH],
            'RelayState' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return redirect()->to(Filament::getCurrentOrDefaultPanel()->getLoginUrl(), 303);
        }

        $validated = $validator->validated();
        $key = Str::random(40);
        Cache::put(self::ACS_CACHE_PREFIX.$key, [
            'identity_provider_id' => $identityProvider->id,
            'SAMLResponse' => $validated['SAMLResponse'],
            'RelayState' => $validated['RelayState'] ?? null,
        ], self::ACS_HANDOVER_TTL);

        return redirect()->route('auth.saml.acs.complete', ['identityProvider' => $identityProvider, 'key' => $key], 303);
    }

    /** SAML-ACS, Stufe 2: Auswertung mit Session (State, InResponseTo). */
    public function samlAcsComplete(Request $request, IdentityProvider $identityProvider, string $key): Response
    {
        // pull: Schlüssel ist nur einmal einlösbar.
        $payload = Cache::pull(self::ACS_CACHE_PREFIX.$key);

        if (! is_array($payload) || $payload['identity_provider_id'] !== $identityProvider->id) {
            return $this->backToLogin(__('auth_admin.saml.invalid_state'));
        }

        // Die ursprüngliche POST-Nachricht rekonstruieren, mit der Session dieses Requests.
        $samlRequest = Request::create($identityProvider->acsUrl(), 'POST', array_filter([
            'SAMLResponse' => $payload['SAMLResponse'],
            'RelayState' => $payload['RelayState'],
        ]));
        $samlRequest->setLaravelSession($request->session());

        return $this->complete($identityProvider, $samlRequest, $request);
    }

    /**
     * SAML Single Logout Service (HTTP-Redirect): Antwort auf unseren LogoutRequest
     * oder vom IdP ausgelöste Abmeldung.
     */
    public function samlSls(Request $request, IdentityProvider $identityProvider): Response
    {
        $driver = $this->drivers->for($identityProvider->type);
        abort_unless($driver instanceof SamlDriver, 404);

        try {
            $message = $driver->receiveLogout($identityProvider, $request);
        } catch (Throwable $e) {
            Log::warning('Ungültige SAML-Logout-Nachricht', ['identity_provider' => $identityProvider->slug, 'exception' => $e]);

            return $this->backToLogin(__('auth_admin.logout.idp_unconfirmed'), 'sso_info');
        }

        if (! $message->isRequest) {
            $pending = (array) $request->session()->pull(SamlDriver::LOGOUT_SESSION_KEY, []);
            $confirmed = $message->successful
                && ($pending['identity_provider_id'] ?? null) === $identityProvider->id
                && hash_equals((string) ($pending['request_id'] ?? ''), (string) $message->inResponseTo);

            return $this->backToLogin(__($confirmed ? 'auth_admin.logout.completed' : 'auth_admin.logout.idp_unconfirmed'), 'sso_info');
        }

        // Vom IdP ausgelöst: nur die Sitzung beenden, die zu dieser IdP-Identität gehört.
        $session = SsoSession::fromArray((array) $request->session()->get(SsoSession::SESSION_KEY, []));
        $guard = Auth::guard(Filament::getCurrentOrDefaultPanel()->getAuthGuard());

        if ($guard->check() && $session !== null
            && $session->identityProviderId === $identityProvider->id
            && hash_equals($session->nameId, (string) $message->nameId)) {
            Context::add('logout_method', 'idp:'.$identityProvider->slug);
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Der IdP erwartet immer eine Antwort, auch wenn keine passende Sitzung bestand.
        return $message->respond();
    }

    public function metadata(IdentityProvider $identityProvider): Response
    {
        $driver = $this->drivers->for($identityProvider->type);

        abort_unless($driver instanceof ProvidesMetadata, 404);

        return $driver->metadata($identityProvider);
    }

    /**
     * Gemeinsamer Abschluss: Identität vom Treiber, Zuordnung, Login.
     *
     * @param  Request  $protocolRequest  Request mit den IdP-Daten (bei SAML rekonstruiert)
     */
    private function complete(IdentityProvider $identityProvider, Request $protocolRequest, Request $request): Response
    {
        $driver = $this->driverOrNull($identityProvider);

        if ($driver === null) {
            return $this->backToLogin(__('auth_admin.login.sso_unavailable'));
        }

        try {
            $identity = $driver->identity($identityProvider, $protocolRequest);
            $user = $this->resolver->resolve($identityProvider, $identity);
        } catch (BusinessRuleException $e) {
            return $this->backToLogin($e->getMessage());
        } catch (Throwable $e) {
            // Protokollfehler (ungültige Signatur, abgelaufener State …): Details nur ins Log.
            Log::warning('SSO-Anmeldung fehlgeschlagen', ['identity_provider' => $identityProvider->slug, 'exception' => $e]);

            return $this->backToLogin(__('auth_admin.login.sso_unavailable'));
        }

        Context::add('auth_method', 'sso:'.$identityProvider->slug);
        Auth::guard(Filament::getCurrentOrDefaultPanel()->getAuthGuard())->login($user);
        $request->session()->regenerate();
        $request->session()->put(SsoSession::SESSION_KEY, $this->ssoSession($identityProvider, $identity)->toArray());

        return redirect()->intended(Filament::getCurrentOrDefaultPanel()->getUrl());
    }

    private function driverOrNull(IdentityProvider $provider): ?SsoDriver
    {
        if (! $provider->is_enabled || ! $this->config->mode()->allowsSso()) {
            return null;
        }

        return $this->drivers->for($provider->type);
    }

    private function ssoSession(IdentityProvider $provider, SsoIdentity $identity): SsoSession
    {
        return new SsoSession(
            identityProviderId: $provider->id,
            identityProviderSlug: $provider->slug,
            nameId: $identity->providerUserId,
            nameIdFormat: isset($identity->attributes['name_id_format']) ? (string) $identity->attributes['name_id_format'] : null,
            sessionIndex: isset($identity->attributes['session_index']) ? (string) $identity->attributes['session_index'] : null,
        );
    }

    private function backToLogin(string $message, string $key = 'sso_error'): RedirectResponse
    {
        return redirect()
            ->to(Filament::getCurrentOrDefaultPanel()->getLoginUrl())
            ->with($key, $message);
    }
}
