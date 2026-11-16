<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\RoleSimulation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Wendet eine laufende „Ansicht als Rolle“ auf die Anfrage an (auch Livewire-Aktualisierungen). */
final class SimulateRole
{
    public function __construct(private readonly RoleSimulation $simulation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->simulation->apply($user);
        }

        return $next($request);
    }
}
