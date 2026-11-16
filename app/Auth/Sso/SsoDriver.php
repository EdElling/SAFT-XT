<?php

declare(strict_types=1);

namespace App\Auth\Sso;

use App\Models\IdentityProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protokoll-spezifischer SSO-Ablauf (Entra/OIDC in 4b, SAML in 4c).
 */
interface SsoDriver
{
    /** Leitet zum IdP weiter (Authorization-Request bzw. AuthnRequest). */
    public function redirect(IdentityProvider $provider, Request $request): Response;

    /** Wertet die Antwort des IdP aus (Callback bzw. ACS) und liefert die Identität. */
    public function identity(IdentityProvider $provider, Request $request): SsoIdentity;
}
