<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WorkItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ergebnisdatei einer Arbeit herunterladen (Fundament 2, F3a): für die startende Person und mit work.view_any, bis
 * expires_at; danach 410. Immer als Download, nosniff verhindert, dass der Browser den Typ selbst errät.
 */
class WorkDownloadController extends Controller
{
    public function __invoke(string $uuid): StreamedResponse
    {
        $item = WorkItem::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(request()->user()?->can('view', $item) ?? false, 403);
        $file = $item->resultFile();
        $path = $file === null ? null : $item->directory().'/'.$file;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 410);

        return Storage::disk('local')->download($path, $file, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
