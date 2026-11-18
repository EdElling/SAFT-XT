<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\RecordFile as BaseRecordFile;
use Illuminate\Support\Number;

/**
 * Datei in einem Feld vom Typ „Datei“ (B-021). Kein eigenes Änderungsprotokoll: Hinzufügen und Entfernen stehen im
 * Protokoll des Datensatzes (Dateinamen im Feldwert).
 */
class RecordFile extends BaseRecordFile
{
    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /** Im Browser anzeigen statt herunterladen (Bilder, PDF); alles andere nur als Download. */
    public function isInline(): bool
    {
        return $this->isImage() || $this->mime === 'application/pdf';
    }

    public function sizeLabel(): string
    {
        return self::sizeText((int) $this->size);
    }

    /** Grösse lesbar: unter 1 MB ohne Nachkommastelle, z. B. „512 KB“, „3.4 MB“. */
    public static function sizeText(int $bytes): string
    {
        return Number::fileSize($bytes, precision: $bytes < 1024 * 1024 ? 0 : 1);
    }

    public function url(bool $thumbnail = false): string
    {
        return route('files.show', ['file' => $this->id, ...($thumbnail ? ['thumb' => 1] : [])]);
    }

    /** Wert im Datensatz: Kennung und Name. @return array{id: string, name: string} */
    public function toReference(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
