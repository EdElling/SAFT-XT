<?php

declare(strict_types=1);

namespace App\Providers;

use App\Settings\AppSettings;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Übernimmt den Anzeigenamen aus den App-Einstellungen als config('app.name')
 * (Panel, Seitentitel, E-Mails) und als Absendername der E-Mails.
 *
 * Bewusst NICHT betroffen: Cookie-, Session- und Cache-Präfixe – sie werden beim
 * Laden der Konfiguration aus APP_NAME abgeleitet und bleiben stabil.
 */
class BrandingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            $name = trim(app(AppSettings::class)->app_name);
        } catch (Throwable) {
            // Neuinstallation vor "migrate" o. Ä.: APP_NAME bleibt gültig.
            return;
        }

        if ($name === '') {
            return;
        }

        config([
            'app.name' => $name,
            'mail.from.name' => $name,
        ]);
    }
}
