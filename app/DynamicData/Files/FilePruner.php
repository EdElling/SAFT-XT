<?php

declare(strict_types=1);

namespace App\DynamicData\Files;

use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verwaiste Dateien aus Dateifeldern aufräumen (B-021) – für „php artisan files:prune“ und die System-Aufgabe:
 * hochgeladene, aber nie gespeicherte Dateien, Einträge ohne Datensatz oder Feld und Dateien im Speicher ohne Eintrag.
 * Hochgeladenes und Abgelegtes erst nach einer Wartezeit, damit offene Formulare nichts verlieren. Einträge, deren
 * Datei im Speicher fehlt, werden nur gemeldet.
 */
final class FilePruner
{
    public const int DEFAULT_HOURS = 24;

    /**
     * @return array{deleted: int, bytes: int, missing: list<string>}
     */
    public function prune(int $hours = self::DEFAULT_HOURS, bool $dryRun = false): array
    {
        $before = now()->subHours(max(1, $hours));
        $stale = collect();

        // 1. Hochgeladen, aber das Formular wurde nie gespeichert.
        $stale = $stale->merge(RecordFile::query()->whereNull('record_id')->where('created_at', '<', $before)->get());

        // 2. Datensatz oder Feld gibt es nicht mehr (z. B. ausserhalb von SAFT gelöscht).
        foreach (RecordFile::query()->whereNotNull('record_id')->get()->groupBy('custom_table_id') as $tableId => $files) {
            $stale = $stale->merge($this->withoutRecord(CustomTable::query()->with('fields')->find($tableId), $files));
        }

        $bytes = (int) $stale->sum('size');
        $deleted = $stale->count();

        if (! $dryRun) {
            app(RecordFiles::class)->removeStored($stale);
            RecordFile::query()->whereKey($stale->map(static fn (RecordFile $file): string => $file->id)->all())->delete();
        }

        // 3. Dateien im Speicher ohne Eintrag (Vorschaubilder gehören zu ihrer Datei).
        $disk = Storage::disk((string) config('dynamic_data.files.disk', 'local'));
        $known = array_flip(RecordFile::query()->pluck('path')->all());

        foreach ($this->storedFiles($disk) as $path) {
            $original = str_ends_with($path, '.thumb.jpg') ? substr($path, 0, -strlen('.thumb.jpg')) : $path;

            if (isset($known[$original]) || $this->lastModified($disk, $path) >= $before->getTimestamp()) {
                continue;
            }

            $deleted++;
            $bytes += $this->size($disk, $path);

            if (! $dryRun) {
                $disk->delete($path);
            }
        }

        // 4. Nur melden: Eintrag ohne Datei im Speicher.
        $missing = RecordFile::query()->get()
            ->reject(fn (RecordFile $file): bool => $this->exists($file))
            ->map(static fn (RecordFile $file): string => $file->path)
            ->values()
            ->all();

        return ['deleted' => $deleted, 'bytes' => $bytes, 'missing' => $missing];
    }

    /**
     * @param  Collection<int, RecordFile>  $files
     * @return Collection<int, RecordFile>
     */
    private function withoutRecord(?CustomTable $table, Collection $files): Collection
    {
        if ($table === null) {
            return $files;
        }

        $fields = RecordFiles::fileFields($table);
        $names = array_map(static fn ($field): string => $field->name, $fields);
        $existing = [];

        foreach ($files->pluck('record_id')->unique()->chunk(500) as $ids) {
            $existing = [...$existing, ...DynamicRecord::forTable($table)->newQuery()->withTrashed()->whereKey($ids->all())->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all()];
        }

        $existing = array_flip($existing);

        return $files->reject(static fn (RecordFile $file): bool => isset($existing[(string) $file->record_id]) && in_array($file->field, $names, true))->values();
    }

    /** @return list<string> */
    private function storedFiles(Filesystem $disk): array
    {
        try {
            return $disk->allFiles(trim((string) config('dynamic_data.files.directory', 'files'), '/'));
        } catch (Throwable) {
            return [];
        }
    }

    private function lastModified(Filesystem $disk, string $path): int
    {
        try {
            return $disk->lastModified($path);
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    private function size(Filesystem $disk, string $path): int
    {
        try {
            return $disk->size($path);
        } catch (Throwable) {
            return 0;
        }
    }

    private function exists(RecordFile $file): bool
    {
        try {
            return Storage::disk($file->disk)->exists($file->path);
        } catch (Throwable) {
            return true;
        }
    }
}
