<?php

declare(strict_types=1);

namespace App\Auth\Sso;

use App\Models\IdentityProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Treiber, die beim Abmelden auch die Sitzung beim IdP beenden können. */
interface SupportsSingleLogout
{
    /** Weiterleitung zum IdP-Logout (nach der lokalen Abmeldung). */
    public function logoutRedirect(IdentityProvider $provider, SsoSession $session, Request $request): Response;
}
