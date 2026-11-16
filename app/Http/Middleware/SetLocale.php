<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprache je Anfrage: Einstellung des Benutzers, dann Sitzung, Cookie, Browser, Vorgabe.
 * Die Sitzung wird mitgeschrieben, denn der Sprachumschalter liest sie zuerst.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);

        if ($request->hasSession()) {
            $request->session()->put('locale', $locale);
        }

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();

        $candidates = [
            $user instanceof User ? $user->locale : null,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->cookie(AppLocale::COOKIE),
            ...array_map(static fn (string $language): string => strtolower(substr($language, 0, 2)), $request->getLanguages()),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && AppLocale::isAvailable($candidate)) {
                return $candidate;
            }
        }

        return (string) config('locales.default');
    }
}
