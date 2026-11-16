<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\AppLocale;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use Illuminate\Support\Facades\Cookie;

/** Speichert die im Umschalter gewählte Sprache am Benutzer und für die Anmeldeseite. */
final class StoreSelectedLocale
{
    public function handle(LocaleChanged $event): void
    {
        // Der Wert kommt aus dem Browser.
        if (! AppLocale::isAvailable($event->locale)) {
            return;
        }

        Cookie::queue(Cookie::forever(AppLocale::COOKIE, $event->locale));

        $user = auth()->user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $event->locale])->save();
        }
    }
}
