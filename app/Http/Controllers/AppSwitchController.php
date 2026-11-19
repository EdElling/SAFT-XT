<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Apps\CurrentApp;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Umschalter in der Titelzeile (B-107): wechselt die aktuelle App und führt zu ihrem Dashboard. */
final class AppSwitchController
{
    public function __invoke(Request $request, int $app, CurrentApp $current): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $current->switch($user, $app), 404);

        return redirect(Dashboard::getUrl());
    }
}
