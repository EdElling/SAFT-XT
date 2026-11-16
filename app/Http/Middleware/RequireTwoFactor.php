<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TwoFactor;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ersetzt Filaments Middleware für die Pflicht zur Zwei-Faktor-Anmeldung: Wer sie laut Einstellung braucht und noch
 * nicht eingerichtet hat, kommt auf die Einrichtungsseite. Filament fragt die Pflicht nur beim Registrieren der Routen
 * ab, deshalb entscheidet diese Middleware je Anfrage und Person (TwoFactor::isRequiredFor()).
 */
final class RequireTwoFactor
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || $this->twoFactor->isSetUp($user) || ! $this->twoFactor->isRequiredFor($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
