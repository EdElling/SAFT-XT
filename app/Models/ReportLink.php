<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\ReportLink as BaseReportLink;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasTranslatedAttributes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabellenverknüpfung (für Auswertungen und Master-Detail): Feld der Quelltabelle → eindeutiges Feld der Zieltabelle (viele zu
 * einem, wie ein Verknüpfungsfeld). Auch zwischen Quellen: Auswertungen rechnen in SAFTs Datenbank,
 * angebundene Tabellen über ihren Datenstand. In der Quelldatenbank entsteht nichts.
 */
class ReportLink extends BaseReportLink
{
    use HasAuditLog;
    use HasTranslatedAttributes;

    /** @var list<string> */
    public array $translatable = ['label'];

    /** @return BelongsTo<CustomTable, $this> */
    public function sourceTable(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'source_table_id');
    }

    /** @return BelongsTo<CustomField, $this> */
    public function sourceField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'source_field_id');
    }

    /** @return BelongsTo<CustomTable, $this> */
    public function targetTable(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'target_table_id');
    }

    /** @return BelongsTo<CustomField, $this> leer = Spalte id einer SAFT-Tabelle */
    public function targetField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'target_field_id');
    }

    /** Spalte der Zieltabelle, auf die verknüpft wird. */
    public function targetColumn(): string
    {
        return $this->targetField?->name ?? 'id';
    }
}
