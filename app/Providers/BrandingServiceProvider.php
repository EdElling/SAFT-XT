<?php

declare(strict_types=1);

namespace App\Providers;

use App\Settings\AppSettings;
use App\Support\MailSettings;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Übernimmt den Anzeigenamen aus den App-Einstellungen als config('app.name')
 * (Panel, Seitentitel, E-Mails) und als Absendername der E-Mails, dazu den E-Mail-Versand (MailSettings).
 *
 * Bewusst NICHT betroffen: Cookie-, Session- und Cache-Präfixe – sie werden beim
 * Laden der Konfiguration aus APP_NAME abgeleitet und bleiben stabil.
 */
class BrandingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            $settings = app(AppSettings::class);
            $name = trim($settings->app_name);
        } catch (Throwable) {
            // Neuinstallation vor "migrate" o. Ä.: APP_NAME bleibt gültig.
            return;
        }

        if ($name !== '') {
            config([
                'app.name' => $name,
                'mail.from.name' => $name,
            ]);
        }

        // E-Mail-Versand aus den Einstellungen (B-124, D3b); eigener Absendername geht vor den Anzeigenamen.
        try {
            MailSettings::apply($settings);
        } catch (Throwable) {
            // Einstellungen noch ohne die Mail-Werte (vor der Migration): .env gilt.
        }
    }
}
