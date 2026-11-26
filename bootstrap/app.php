<?php

declare(strict_types=1);

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ErrorPresenter;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServerTiming;
use App\Http\Middleware\SetLocale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Zuerst: misst die ganze Antwort samt Session und Rechten (nur mit SERVER_TIMING=true).
        $middleware->prepend(ServerTiming::class);
        // Sicherheits-Header für alle Antworten (Seiten, Livewire, Downloads, Fehlerseiten).
        $middleware->append(SecurityHeaders::class);
        // Auch Livewire-Anfragen laufen über "web" und brauchen die Sprache des Benutzers.
        $middleware->web(append: [SetLocale::class]);
        // Eigene Routen mit „auth“ (Bilder der Dokumentation, Theme, Rollen-Ansicht): Gäste zur Anmeldung von Filament.
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = static fn (Request $request): bool => ! $request->hasHeader('X-Livewire')
            && ($request->is('api/*') || $request->expectsJson());

        $exceptions->shouldRenderJsonWhen($wantsJson);

        // Unmögliche ID in der Adresse (z. B. /users/abc/edit): Die Datenbank lehnt den Wert ab,
        // für Benutzer ist der Datensatz schlicht nicht vorhanden.
        $exceptions->map(static function (QueryException $e): Throwable {
            $record = request()->route()?->parameter('record');

            return in_array($e->errorInfo[0] ?? null, ['22P02', '22003'], true)
                && is_string($record)
                && in_array($record, $e->getBindings(), true)
                    ? new NotFoundHttpException(previous: $e)
                    : $e;
        });

        // Fachliche Regelverstöße sind erwartet und kein Fehler im Sinne des Betriebs.
        $exceptions->dontReport([BusinessRuleException::class]);

        // Referenz in jedem Log-Eintrag – identisch mit der Anzeige beim Nutzer.
        $exceptions->context(static fn (Throwable $e): array => array_filter([
            'error_reference' => app(ErrorPresenter::class)->referenceFor($e),
        ]));

        // Erwartbare DB-Konflikte (Unique, FK, …) nur als Warning, nicht als Error.
        $exceptions->report(static function (QueryException $e): ?bool {
            $presenter = app(ErrorPresenter::class);

            if (! $presenter->isExpected($e)) {
                return null; // Standard-Reporting
            }

            Log::warning($e->getMessage(), ['exception' => $e, 'error_reference' => $presenter->referenceFor($e)]);

            return false;
        });

        // Standardisierte JSON-Fehlerstruktur für API-/JSON-Clients.
        $exceptions->render(static function (Throwable $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            $error = app(ErrorPresenter::class)->present($e);

            return new JsonResponse(
                $error->toArray(),
                $error->status,
                $e instanceof HttpExceptionInterface ? $e->getHeaders() : [],
            );
        });
    })->create();
