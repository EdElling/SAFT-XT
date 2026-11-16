<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Context;

/**
 * Setzt last_login_at direkt per Query (kein Model-Audit, kein updated_at-Touch)
 * und protokolliert die Anmeldung inkl. Methode (Passwort oder SSO-Anbieter).
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $now = now();

        User::withTrashed()
            ->whereKey($event->user->getKey())
            ->toBase()
            ->update(['last_login_at' => $now]);

        $event->user->setRawAttributes([...$event->user->getAttributes(), 'last_login_at' => $now], sync: true);

        activity('auth')
            ->performedOn($event->user)
            ->causedBy($event->user)
            ->event('login')
            ->withProperties([
                'method' => Context::get('auth_method', 'password'),
                'ip' => request()->ip(),
            ])
            ->log('login');
    }
}
