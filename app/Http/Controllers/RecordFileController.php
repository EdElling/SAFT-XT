<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DynamicData\DataAccess;
use App\DynamicData\Files\RecordFiles;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Datei aus einem Feld vom Typ „Datei“ ausliefern (B-021) – nur mit Leserecht auf die Tabelle und den Datensatz;
 * sonst „nicht gefunden“, damit sich nicht erraten lässt, welche Dateien es gibt. Noch keinem Datensatz zugeordnete
 * Dateien (gerade hochgeladen) sieht nur, wer sie hochgeladen hat. Bilder und PDF im Browser (mit ?download=1 als
 * Download), alles andere als Download; nosniff verhindert, dass der Browser den Typ selbst errät.
 */
final class RecordFileController
{
    public function __invoke(Request $request, string $file, RecordFiles $files): StreamedResponse
    {
        $user = $request->user();
        $record = RecordFile::query()->with('custom_table')->find($file);

        abort_unless($user instanceof User && $record !== null && $this->allows($user, $record), 404);

        $path = $request->boolean('thumb') ? ($files->thumbnail($record) ?? $record->path) : $record->path;
        $thumbnail = $path !== $record->path;

        try {
            $stream = Storage::disk($record->disk)->readStream($path);
        } catch (Throwable) {
            $stream = null;
        }

        abort_if($stream === null || $stream === false, 404);

        $disposition = HeaderUtils::makeDisposition(
            $record->isInline() && ! $request->boolean('download') ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $record->name,
            (string) preg_replace('/[^\x20-\x7E]/', '_', $record->name),
        );

        return response()->stream(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $record->mime,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            // Ausgelieferte Dateien dürfen keine Skripte ausführen und nichts nachladen.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'",
        ]);
    }

    private function allows(User $user, RecordFile $file): bool
    {
        $table = $file->custom_table;

        // Wie das Panel: ein während der Sitzung deaktiviertes Konto lädt nichts mehr.
        if (! $user->is_active || $table === null || ! $table->is_active || ! DataAccess::allows($user, $table, 'view')) {
            return false;
        }

        if ($file->record_id === null) {
            return $file->uploaded_by === $user->id;
        }

        $record = DynamicRecord::forTable($table)->newQuery()->withTrashed()->find($file->record_id);

        if ($record === null) {
            return false;
        }

        // Im Papierkorb nur für Personen, die Datensätze löschen (und damit den Papierkorb sehen) dürfen.
        return $record->getAttribute('deleted_at') === null || DataAccess::allows($user, $table, 'delete');
    }
}
