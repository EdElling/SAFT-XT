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
 *
 * Dazu die drei langsamsten Abfragen (`q1` bis `q3`): Dauer, Verbindung und gekürztes SQL ohne Werte; „erste“ markiert
 * die erste Abfrage einer Verbindung im Request, deren Dauer den Verbindungsaufbau enthält (Laravel verbindet erst
 * bei der ersten Abfrage). So lässt sich auf entfernten Datenbankrechnern Verbindungsaufbau von langsamen Abfragen
 * unterscheiden (Strato, 26.11.2026).
 */
final class ServerTiming
{
    private static int $queries = 0;

    private static float $queryMs = 0.0;

    /** Anzahl der langsamsten Abfragen im Header. */
    private const int SLOWEST = 3;

    /** @var list<array{ms: float, label: string}> */
    private static array $slowest = [];

    /** @var array<string, true> Verbindungen mit mindestens einer Abfrage im Request */
    private static array $connections = [];

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
        self::$slowest = [];
        self::$connections = [];

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
        foreach (self::slowest() as $i => $query) {
            $metrics[] = sprintf('q%d;dur=%.1f;desc="%s"', $i + 1, $query['ms'], $query['label']);
        }
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

    /**
     * Langsamste Abfragen des laufenden Requests, absteigend (für Tests).
     *
     * @return list<array{ms: float, label: string}>
     */
    public static function slowest(): array
    {
        return self::$slowest;
    }

    private static function record(QueryExecuted $query): void
    {
        self::$queries++;
        self::$queryMs += $query->time;
        $first = ! isset(self::$connections[$query->connectionName]);
        self::$connections[$query->connectionName] = true;

        if (count(self::$slowest) === self::SLOWEST && $query->time <= self::$slowest[self::SLOWEST - 1]['ms']) {
            return;
        }

        self::$slowest[] = ['ms' => (float) $query->time, 'label' => self::label($query, $first)];
        usort(self::$slowest, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        self::$slowest = array_slice(self::$slowest, 0, self::SLOWEST);
    }

    /** Verbindung und SQL ohne Werte, gekürzt, nur druckbares ASCII (Header). */
    private static function label(QueryExecuted $query, bool $first): string
    {
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", '?', $query->sql);
        $sql = (string) preg_replace('/[^\x20-\x7E]|["\\`]/', '', $sql);
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));

        return $query->connectionName.($first ? ' (erste)' : '').': '.(strlen($sql) > 80 ? substr($sql, 0, 79).'~' : $sql);
    }

    private function listen(): void
    {
        if (self::$listeningOn === $this->events) {
            return;
        }

        self::$listeningOn = $this->events;
        $this->events->listen(QueryExecuted::class, static fn (QueryExecuted $query) => self::record($query));
    }
}
