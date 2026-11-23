<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\DocumentLetterhead as BaseDocumentLetterhead;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Briefpapier für Dokumente (B-124, D1a): die Zeile ohne App gilt für die Installation, eine Zeile je App enthält nur
 * Abweichungen – leere Werte erben von der Installation (App\Documents\LetterheadResolver). Dateien liegen auf dem
 * privaten Speicher; ersetzte oder entfernte Dateien werden gelöscht.
 */
class DocumentLetterhead extends BaseDocumentLetterhead
{
    use HasAuditLog;

    public const string DIRECTORY = 'documents/letterheads';

    public const string FONT_DIRECTORY = 'documents/fonts';

    /** @var list<string> */
    public const array FILES = ['first_page_path', 'next_pages_path', 'logo_path', 'font_regular_path', 'font_bold_path', 'font_italic_path'];

    public const string LEFT = 'left';

    public const string RIGHT = 'right';

    protected static function booted(): void
    {
        static::updated(static function (self $letterhead): void {
            foreach (self::FILES as $attribute) {
                $old = $letterhead->getOriginal($attribute);

                if ($letterhead->wasChanged($attribute) && filled($old)) {
                    Storage::disk('local')->delete((string) $old);
                }
            }
        });

        static::deleted(static function (self $letterhead): void {
            Storage::disk('local')->delete(array_values(array_filter($letterhead->only(self::FILES))));
        });
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** Briefpapier der Installation (wird bei Bedarf angelegt). */
    public static function installation(): self
    {
        return self::query()->whereNull('app_id')->firstOrCreate(['app_id' => null], ['pdfa' => true]);
    }
}
