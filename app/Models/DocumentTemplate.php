<?php

declare(strict_types=1);

namespace App\Models;

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

    protected static function booted(): void
    {
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
