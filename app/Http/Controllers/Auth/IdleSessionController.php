<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\IdleTimeout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Für das Skript der Warnung vor dem Abmelden (B-162): Restzeit abfragen, ohne dass das als Bedienung zählt, und
 * Bedienung melden, die keine Anfrage ausgelöst hat (langes Tippen, „Weiterarbeiten“). Gezählt wird in LogoutWhenIdle.
 */
final class IdleSessionController
{
    public function __construct(private readonly IdleTimeout $idle) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['remaining' => $this->idle->remainingSeconds($request)]);
    }

    public function activity(Request $request): JsonResponse
    {
        return $this->status($request);
    }
}
