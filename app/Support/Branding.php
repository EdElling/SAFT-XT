<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\AppSettings;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Logo und Symbol im Browser-Tab (B-126): das SAFT-Logo (Safttropfen) oder ein eigenes aus den Einstellungen.
 * Das eigene Logo liegt auf dem privaten Speicher und wird über /branding/logo ausgeliefert – auch vor der Anmeldung,
 * ohne öffentlichen Speicher (storage:link).
 */
final class Branding
{
    /** Erlaubte Dateiarten des eigenen Logos; SVG nicht (kann Skripte enthalten). */
    public const array MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public const string DIRECTORY = 'branding';

    /** Pfad des eigenen Logos, wenn es eines gibt und die Datei vorhanden ist. */
    public static function customLogo(): ?string
    {
        try {
            $path = app(AppSettings::class)->logo_path;
        } catch (Throwable) {
            return null;
        }

        return filled($path) && Storage::disk('local')->exists((string) $path) ? (string) $path : null;
    }

    /** Adresse des Logos (eigenes oder SAFT-Logo); die Version wechselt mit der Datei, damit Browser neu laden. */
    public static function logoUrl(): string
    {
        $custom = self::customLogo();

        return $custom === null ? asset('favicon.svg') : route('branding.logo', ['v' => substr(md5($custom), 0, 8)]);
    }

    /** Symbol im Browser-Tab: das eigene Logo nur, wenn so eingestellt. */
    public static function faviconUrl(): string
    {
        try {
            $asFavicon = app(AppSettings::class)->logo_as_favicon;
        } catch (Throwable) {
            $asFavicon = false;
        }

        return $asFavicon && self::customLogo() !== null ? self::logoUrl() : asset('favicon.svg');
    }

    /** Titelleiste und Anmeldeseite: Logo neben dem Namen. */
    public static function brand(): Htmlable
    {
        return new HtmlString(
            '<span style="display: inline-flex; align-items: center; gap: .5rem; height: 100%">'
            .'<img src="'.e(self::logoUrl()).'" alt="" style="height: 100%; width: auto" data-saft-logo>'
            .'<span style="font-weight: 700; font-size: 1.125rem; line-height: 1; white-space: nowrap">'.e((string) config('app.name')).'</span>'
            .'</span>'
        );
    }
}
