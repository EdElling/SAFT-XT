<?php

declare(strict_types=1);

namespace App\DynamicData\Files;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dateien in Feldern vom Typ „Datei“ (B-021): ablegen (mit zufälligem Namen), Datensätzen zuordnen, ersetzte und
 * entfernte löschen, Vorschaubilder erzeugen. Im Datensatz steht je Datei {id, name}; Pfad und Angaben in record_files.
 */
final class RecordFiles
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('dynamic_data.files.disk', 'local'));
    }

    public function diskName(): string
    {
        return (string) config('dynamic_data.files.disk', 'local');
    }

    /** Hochgeladene Datei ablegen und erfassen (noch ohne Datensatz); liefert den Pfad für das Formular. */
    public function store(CustomTable $table, CustomField $field, UploadedFile $upload): RecordFile
    {
        $directory = trim((string) config('dynamic_data.files.directory', 'files'), '/').'/'.$table->name.'/'.$field->name;
        $extension = strtolower((string) ($upload->guessExtension() ?: $upload->getClientOriginalExtension()));
        $path = $this->disk()->putFileAs($directory, $upload, Str::uuid()->toString().($extension !== '' ? '.'.$extension : ''), ['visibility' => 'private']);
        $dimensions = str_starts_with((string) $upload->getMimeType(), 'image/') ? @getimagesize($upload->getRealPath()) : false;

        return RecordFile::query()->create([
            'id' => (string) Str::uuid(),
            'custom_table_id' => $table->id,
            'field' => $field->name,
            'record_id' => null,
            'disk' => $this->diskName(),
            'path' => (string) $path,
            'name' => self::cleanName($upload->getClientOriginalName()),
            'mime' => (string) ($upload->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $upload->getSize(),
            'width' => is_array($dimensions) ? $dimensions[0] : null,
            'height' => is_array($dimensions) ? $dimensions[1] : null,
            'uploaded_by' => auth()->id(),
        ]);
    }

    /**
     * Pfade aus dem Formular → Wert im Datensatz (Kennung und Name, in dieser Reihenfolge); unbekannte Pfade entfallen.
     *
     * @param  array<array-key, string>|string|null  $paths
     * @return list<array{id: string, name: string}>|null
     */
    public function referencesFor(CustomTable $table, CustomField $field, array|string|null $paths): ?array
    {
        $paths = array_values(array_filter(is_array($paths) ? $paths : [$paths], static fn (mixed $p): bool => is_string($p) && $p !== ''));

        if ($paths === []) {
            return null;
        }

        $files = RecordFile::query()->where('custom_table_id', $table->id)->where('field', $field->name)->whereIn('path', $paths)->get()->keyBy('path');
        $references = array_values(array_filter(array_map(static fn (string $p): ?array => $files->get($p)?->toReference(), $paths)));

        return $references === [] ? null : $references;
    }

    /**
     * Wert im Datensatz → Pfade für das Formular.
     *
     * @return list<string>
     */
    public function pathsFor(mixed $value): array
    {
        $ids = self::ids($value);

        if ($ids === []) {
            return [];
        }

        $files = RecordFile::query()->whereIn('id', $ids)->pluck('path', 'id');

        return array_values(array_filter(array_map(static fn (string $id): ?string => $files[$id] ?? null, $ids)));
    }

    /**
     * Nach dem Speichern: neue Dateien dem Datensatz zuordnen, ersetzte und entfernte löschen. Nur Dateien dieser
     * Tabelle und dieses Felds; ein Wert kann keine fremde Datei „übernehmen“.
     */
    public function sync(DynamicRecord $record, CustomTable $table): void
    {
        $recordId = (string) $record->getKey();

        foreach (self::fileFields($table) as $field) {
            $ids = self::ids($record->getAttribute($field->name));
            $query = RecordFile::query()->where('custom_table_id', $table->id)->where('field', $field->name);

            if ($ids !== []) {
                (clone $query)->whereIn('id', $ids)->whereNull('record_id')->update(['record_id' => $recordId]);
            }

            $stale = (clone $query)->where('record_id', $recordId);

            if ($ids !== []) {
                $stale->whereNotIn('id', $ids);
            }

            $this->deleteFiles($stale->get());
        }
    }

    /**
     * Hinweis beim endgültigen Löschen eines Felds bzw. einer Tabelle: wie viele Dateien mit welcher Grösse dabei
     * gelöscht werden (auch die gelöschter Datensätze im Papierkorb); null, wenn keine.
     */
    public static function dropWarning(CustomTable $table, ?string $field = null): ?string
    {
        $query = RecordFile::query()->where('custom_table_id', $table->id)->when($field !== null, static fn ($q) => $q->where('field', $field));
        $count = (clone $query)->count();

        return $count === 0 ? null : trans_choice('tables.files_drop_warning', $count, [
            'count' => $count,
            'size' => Number::fileSize((int) $query->sum('size'), precision: 1),
        ]);
    }

    public function deleteForRecord(CustomTable $table, string $recordId): void
    {
        $this->deleteFiles(RecordFile::query()->where('custom_table_id', $table->id)->where('record_id', $recordId)->get());
    }

    public function deleteForField(CustomTable $table, string $field): void
    {
        $this->deleteFiles(RecordFile::query()->where('custom_table_id', $table->id)->where('field', $field)->get());
    }

    public function deleteForTable(CustomTable $table): void
    {
        $this->deleteFiles(RecordFile::query()->where('custom_table_id', $table->id)->get());
    }

    /**
     * Nur die Dateien im Speicher löschen (Einträge schon entfernt, z. B. mit der Tabelle).
     *
     * @param  iterable<RecordFile>  $files
     */
    public function removeStored(iterable $files): void
    {
        foreach ($files as $file) {
            try {
                Storage::disk($file->disk)->delete([$file->path, self::thumbnailPath($file->path)]);
            } catch (Throwable) {
                // Schon weg oder Speicher nicht erreichbar: nichts zu tun.
            }
        }
    }

    /** @param  iterable<RecordFile>  $files */
    private function deleteFiles(iterable $files): void
    {
        foreach ($files as $file) {
            try {
                $disk = Storage::disk($file->disk);
                $disk->delete([$file->path, self::thumbnailPath($file->path)]);
            } catch (Throwable) {
                // Datei schon weg oder Speicher nicht erreichbar: den Eintrag trotzdem entfernen.
            }

            $file->delete();
        }
    }

    /** Vorschaubild (JPEG, längste Seite thumbnail_size); null = keines möglich (kein Bild, kein GD). */
    public function thumbnail(RecordFile $file): ?string
    {
        if (! $file->isImage() || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $disk = Storage::disk($file->disk);
        $path = self::thumbnailPath($file->path);

        if ($disk->exists($path)) {
            return $path;
        }

        try {
            $image = @imagecreatefromstring((string) $disk->get($file->path));

            if ($image === false) {
                return null;
            }

            $size = (int) config('dynamic_data.files.thumbnail_size', 160);
            [$width, $height] = [imagesx($image), imagesy($image)];
            $scale = min(1, $size / max($width, $height, 1));
            $thumb = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

            ob_start();
            imagejpeg($thumb === false ? $image : $thumb, null, 80);
            $disk->put($path, (string) ob_get_clean(), ['visibility' => 'private']);

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<CustomField> */
    public static function fileFields(CustomTable $table): array
    {
        return $table->fields->filter(static fn (CustomField $f): bool => $f->type === FieldType::File)->values()->all();
    }

    /** @return list<string> Kennungen aus einem Feldwert */
    public static function ids(mixed $value): array
    {
        $value = self::items($value);

        return is_array($value)
            ? array_values(array_filter(array_map(static fn (mixed $item): ?string => is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : null, $value)))
            : [];
    }

    /** @return list<string> Dateinamen aus einem Feldwert (Liste, Export, Protokoll) */
    public static function names(mixed $value): array
    {
        $value = self::items($value);

        return is_array($value)
            ? array_values(array_filter(array_map(static fn (mixed $item): ?string => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : null, $value)))
            : [];
    }

    /**
     * Feldwert als Liste von {id, name}: aus JSON, und auch eine einzelne Angabe – so reicht Filament den Wert eines
     * Felds mit höchstens einer Datei an das Formular weiter.
     */
    private static function items(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) && array_key_exists('id', $value) ? [$value] : $value;
    }

    private static function thumbnailPath(string $path): string
    {
        return $path.'.thumb.jpg';
    }

    /** Originalname ohne Pfad und Steuerzeichen, höchstens 255 Zeichen. */
    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename(str_replace('\\', '/', $name))));

        return mb_substr($name !== '' ? $name : 'datei', 0, 255);
    }
}
