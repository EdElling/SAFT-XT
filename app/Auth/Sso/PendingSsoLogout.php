<?php

declare(strict_types=1);

namespace App\Auth\Sso;

/**
 * Überbrückt den SSO-Kontext vom Logout-Event (Session noch vorhanden) bis zur
 * LogoutResponse (Session bereits invalidiert). Request-scoped gebunden.
 */
final class PendingSsoLogout
{
    private ?SsoSession $session = null;

    public function set(SsoSession $session): void
    {
        $this->session = $session;
    }

    public function pull(): ?SsoSession
    {
        [$session, $this->session] = [$this->session, null];

        return $session;
    }
}
