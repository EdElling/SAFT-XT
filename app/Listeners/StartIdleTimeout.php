<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Auth\IdleTimeout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * Neue Anmeldung (Passwort, Zwei-Faktor, SSO): Uhr der Leerlauf-Grenze und Anmeldezeitpunkt für „Angemeldet bleiben“
 * starten (B-162). Eine Wiederanmeldung über das Remember-Cookie prüft LogoutWhenIdle und startet nichts neu.
 */
class StartIdleTimeout
{
    public function __construct(private readonly IdleTimeout $idle) {}

    public function handle(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        if (! $guard instanceof SessionGuard || $guard->viaRemember()) {
            return;
        }

        $this->idle->startSession();
    }
}
