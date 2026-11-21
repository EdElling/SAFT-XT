<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Liefert das Logo aus (B-126): das eigene aus den Einstellungen, sonst das SAFT-Logo. Ohne Anmeldung. */
final class BrandingController
{
    public function logo(): Response
    {
        $path = Branding::customLogo();

        if ($path === null) {
            return response()->file(public_path('favicon.svg'), ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600']);
        }

        $disk = Storage::disk('local');
        $mime = (string) $disk->mimeType($path);

        // Nur Bilder der erlaubten Arten ausliefern, nie etwas anderes aus dem Speicher.
        abort_unless(in_array($mime, Branding::MIMES, true), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
