<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Bilder der Online-Hilfe aus docs/manual/images – nur dieser Ordner, nur PNG/SVG (Route prüft das Muster). */
final class DocumentationImageController
{
    public function __invoke(string $lang, string $file): BinaryFileResponse
    {
        $base = realpath(base_path('docs/manual/images'));
        $path = realpath(base_path("docs/manual/images/{$lang}/{$file}"));
        abort_unless($base !== false && $path !== false && str_starts_with($path, $base.DIRECTORY_SEPARATOR), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.svg') ? 'image/svg+xml' : 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
