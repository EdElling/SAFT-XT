<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\RoleSimulation;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Beendet die „Ansicht als Rolle“ (immer möglich, unabhängig von den Rechten der Rolle). */
final class RoleSimulationController
{
    public function stop(Request $request, RoleSimulation $simulation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $simulation->stop($user);

        return redirect()->to(Dashboard::getUrl(panel: 'admin'));
    }
}
