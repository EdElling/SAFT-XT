<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\IdleTimeout;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meldet nach der Leerlauf-Grenze ab und zählt Bedienung (B-162, siehe IdleTimeout). Läuft in der Gruppe „web“ und
 * damit auch für Livewire-Anfragen und eigene Routen. Eine Livewire-Anfrage folgt der Weiterleitung zur Anmeldeseite;
 * der Hinweis steht deshalb im Parameter der Adresse, nicht in der Sitzung.
 */
final class LogoutWhenIdle
{
    public function __construct(private readonly IdleTimeout $idle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard();

        if (! $guard instanceof SessionGuard || ! $request->hasSession() || ! $guard->check()) {
            return $next($request);
        }

        if ($guard->viaRemember()) {
            $rejection = $this->idle->rememberedRejection($request);

            if ($rejection !== null) {
                return $this->logout($request, $guard, $rejection);
            }

            $this->idle->adoptRemembered($request);
        }

        if ($this->idle->isExpired($request)) {
            return $this->logout($request, $guard, 'idle');
        }

        if ($this->idle->countsAsActivity($request)) {
            $this->idle->touch($request);
        }

        return $next($request);
    }

    private function logout(Request $request, SessionGuard $guard, string $reason): Response
    {
        Context::add('logout_method', $reason === 'idle' ? 'idle' : 'remember_expired');
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->idle->forgetCookie();

        $login = (string) Filament::getDefaultPanel()->getLoginUrl([IdleTimeout::LOGIN_QUERY => $reason]);

        return $request->expectsJson() && ! $request->hasHeader('X-Livewire')
            ? response()->json(['message' => __('auth_admin.idle.logged_out'), 'redirect' => $login], 401)
            : redirect()->to($login);
    }
}
