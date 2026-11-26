<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Schlüsselt die Antwortzeit im Header `Server-Timing` auf (Entwicklerwerkzeuge des Browsers, Netzwerk → Timing):
 * `boot` vom Start von Laravel bis hierher, `db` Anzahl und Dauer der Datenbankabfragen (alle Verbindungen), `app`
 * die übrige Zeit der Anwendung, `total` gesamt. Nur mit SERVER_TIMING=true; gedacht zum Messen auf Hosting ohne
 * Server-Logs, danach wieder ausschalten. Steht als erste globale Middleware, damit Session und Rechte mitzählen.
 */
final class ServerTiming
{
    private static int $queries = 0;

    private static float $queryMs = 0.0;

    /** Dispatcher, an dem der Zähler hängt – je Anwendungsinstanz einmal (Tests bauen sie je Test neu). */
    private static ?Dispatcher $listeningOn = null;

    public function __construct(private readonly Dispatcher $events) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('saft.server_timing')) {
            return $next($request);
        }

        $start = microtime(true);
        $this->listen();
        self::$queries = 0;
        self::$queryMs = 0.0;

        /** @var Response $response */
        $response = $next($request);

        $end = microtime(true);
        $queries = self::queries();
        $queryMs = self::$queryMs;
        $appMs = ($end - $start) * 1000;
        $metrics = [];
        if (defined('LARAVEL_START')) {
            $metrics[] = sprintf('boot;dur=%.1f', ($start - LARAVEL_START) * 1000);
        }
        $metrics[] = sprintf('db;dur=%.1f;desc="%d %s"', $queryMs, $queries, $queries === 1 ? 'query' : 'queries');
        $metrics[] = sprintf('app;dur=%.1f', max(0.0, $appMs - $queryMs));
        $metrics[] = sprintf('total;dur=%.1f', defined('LARAVEL_START') ? ($end - LARAVEL_START) * 1000 : $appMs);

        $response->headers->set('Server-Timing', implode(', ', $metrics));

        return $response;
    }

    /** Anzahl der Abfragen des laufenden Requests (für Tests). */
    public static function queries(): int
    {
        return self::$queries;
    }

    private function listen(): void
    {
        if (self::$listeningOn === $this->events) {
            return;
        }

        self::$listeningOn = $this->events;
        $this->events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            self::$queries++;
            self::$queryMs += $query->time;
        });
    }
}
