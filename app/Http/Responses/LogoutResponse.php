<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Auth\Sso\PendingSsoLogout;
use App\Auth\Sso\SsoDriverRegistry;
use App\Auth\Sso\SupportsSingleLogout;
use App\Models\IdentityProvider;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Nach der lokalen Abmeldung: bei SSO-Anmeldung und aktiviertem Single Logout zum
 * IdP weiterleiten, sonst wie gewohnt zur Login-Seite.
 */
final class LogoutResponse implements LogoutResponseContract
{
    public function __construct(
        private readonly PendingSsoLogout $pending,
        private readonly SsoDriverRegistry $drivers,
    ) {}

    public function toResponse($request): Response
    {
        $session = $this->pending->pull();
        $provider = $session === null ? null : IdentityProvider::query()->find($session->identityProviderId);

        if ($session !== null && $provider !== null && $provider->is_enabled && $provider->setting('single_logout')) {
            $driver = $this->drivers->for($provider->type);

            if ($driver instanceof SupportsSingleLogout) {
                try {
                    return $driver->logoutRedirect($provider, $session, $request);
                } catch (Throwable $e) {
                    // Lokal ist der Nutzer bereits abgemeldet; IdP-Logout ist "best effort".
                    Log::warning('Single Logout beim IdP fehlgeschlagen', ['identity_provider' => $provider->slug, 'exception' => $e]);
                }
            }
        }

        return redirect()->to(Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl());
    }
}
