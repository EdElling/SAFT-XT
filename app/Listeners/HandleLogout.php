<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Auth\Sso\PendingSsoLogout;
use App\Auth\Sso\SsoSession;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Context;

/**
 * Protokolliert Abmeldungen und sichert den SSO-Kontext für Single Logout, bevor
 * die Session invalidiert wird.
 */
class HandleLogout
{
    public function __construct(private readonly PendingSsoLogout $pending) {}

    public function handle(Logout $event): void
    {
        $session = request()->hasSession()
            ? SsoSession::fromArray((array) request()->session()->get(SsoSession::SESSION_KEY, []))
            : null;

        if ($session !== null) {
            $this->pending->set($session);
        }

        if (! $event->user instanceof User) {
            return;
        }

        activity('auth')
            ->performedOn($event->user)
            ->causedBy($event->user)
            ->event('logout')
            ->withProperties([
                'method' => Context::get('logout_method')
                    ?? ($session !== null ? 'sso:'.$session->identityProviderSlug : 'local'),
                'ip' => request()->ip(),
            ])
            ->log('logout');
    }
}
