<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheits-Header für jede Antwort: kein Einbetten in fremde Seiten, kein Erraten von Dateitypen, sparsame
 * Referrer, keine Gerätefunktionen und – nur über HTTPS – HSTS (abschaltbar mit SECURITY_HSTS=false). Header, die
 * eine Antwort schon selbst setzt, bleiben unverändert. Keine vollständige Content-Security-Policy: Filament und
 * Livewire brauchen Inline-Skripte.
 */
final class SecurityHeaders
{
    /** Ein Jahr; ohne includeSubDomains, weil weitere Unterdomänen beim selben Hoster andere Anwendungen sein können. */
    public const string HSTS = 'max-age=31536000';

    /** @var array<string, string> */
    public const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = self::HEADERS;
        if ($request->isSecure() && (bool) config('saft.hsts')) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
