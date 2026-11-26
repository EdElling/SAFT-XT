<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\CustomTableLookup;
use App\Models\Base\DocumentTemplate as BaseDocumentTemplate;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Inhaltsvorlage für Dokumente (B-124, D1b): HTML mit Mustache-Platzhaltern zu einer Grundtabelle einer App. Das
 * Briefpapier (Schrift, Farben, Kopf) kommt aus App\Documents\LetterheadResolver, die Vorlage setzt nur die Daten.
 */
class DocumentTemplate extends BaseDocumentTemplate
{
    use HasAuditLog;

    /** Formate (D4b): nur PDF (Vorgabe), nur Word oder beide. */
    public const string FORMAT_PDF = 'pdf';

    public const string FORMAT_DOCX = 'docx';

    public const string FORMAT_BOTH = 'both';

    public const array FORMATS = [self::FORMAT_PDF, self::FORMAT_DOCX, self::FORMAT_BOTH];

    /** Darf die Vorlage dieses Ausgabeformat (pdf oder docx) erzeugen? */
    public function allowsFormat(string $format): bool
    {
        $formats = in_array($this->formats, self::FORMATS, true) ? $this->formats : self::FORMAT_PDF;

        return $formats === self::FORMAT_BOTH || $formats === $format;
    }

    /** Ausgabeformat, das gilt, wenn niemand wählt: PDF, ausser die Vorlage erlaubt nur Word. */
    public function defaultFormat(): string
    {
        return $this->allowsFormat(self::FORMAT_PDF) ? self::FORMAT_PDF : self::FORMAT_DOCX;
    }

    protected static function booted(): void
    {
        // Je Request gemerkte Details bzw. Vorlagen (CustomTableLookup::remember) verwerfen.
        static::saved(static fn () => app(CustomTableLookup::class)->forget());
        static::deleted(static fn () => app(CustomTableLookup::class)->forget());
        static::creating(static function (self $template): void {
            $template->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return BelongsTo<CustomTable, $this> */
    public function customTable(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class);
    }
}
