<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use App\Models\RecordFile;
use Illuminate\Support\Facades\Storage;

/**
 * Eine Datei aus einem Feld vom Typ „Datei“ (über DataTable::files() und attachFile(), seit API 1.8).
 *
 * @api
 */
final readonly class StoredFile
{
    /** @internal Plugins erhalten Dateien über DataTable. */
    public function __construct(
        public string $id,
        public string $name,
        public string $mime,
        public int $size,
        private string $disk,
        private string $path,
    ) {}

    /** @internal */
    public static function from(RecordFile $file): self
    {
        return new self($file->id, $file->name, $file->mime, (int) $file->size, $file->disk, $file->path);
    }

    /** Adresse zum Ansehen in SAFT (mit den Datenrechten der Person, die sie aufruft). */
    public function url(): string
    {
        return route('files.show', ['file' => $this->id]);
    }

    public function contents(): string
    {
        return (string) Storage::disk($this->disk)->get($this->path);
    }

    /** @return resource|null zum Lesen grosser Dateien */
    public function stream(): mixed
    {
        return Storage::disk($this->disk)->readStream($this->path);
    }
}
