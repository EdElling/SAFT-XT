<?php

declare(strict_types=1);

namespace App\Models;

use App\Apps\AppInterfaces;
use App\DynamicData\CustomTableLookup;
use App\Exceptions\BusinessRuleException;
use App\Models\Base\ReportLink as BaseReportLink;
use App\Models\Concerns\BelongsToApp;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Models\Concerns\HasTranslatedAttributes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabellenverknüpfung (für Auswertungen und Master-Detail): Feld der Quelltabelle → eindeutiges Feld der Zieltabelle (viele zu
 * einem, wie ein Verknüpfungsfeld). Auch zwischen Quellen: Auswertungen rechnen in SAFTs Datenbank,
 * angebundene Tabellen über ihren Datenstand. In der Quelldatenbank entsteht nichts.
 */
class ReportLink extends BaseReportLink
{
    use BelongsToApp;
    use HasAuditLog;
    use HasStableKey;
    use HasTranslatedAttributes;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected static function booted(): void
    {
        // Je Request gemerkte Details bzw. Vorlagen (CustomTableLookup::remember) verwerfen.
        static::saved(static fn () => app(CustomTableLookup::class)->forget());
        static::deleted(static fn () => app(CustomTableLookup::class)->forget());
        // Gehört zur App der Quelltabelle; Ziel in derselben App oder eine freigegebene Tabelle einer anderen App mit
        // freigegebenem Zielfeld (Schnittstelle, B-107 Etappe 5b).
        static::saving(static function (self $link): void {
            $source = CustomTable::query()->whereKey($link->source_table_id)->value('app_id');
            $target = $link->target_table_id === null ? null : CustomTable::query()->with('fields')->find($link->target_table_id);
            $source = $source === null ? null : (int) $source;

            if ($target !== null && AppInterfaces::foreign($target, $source)) {
                $field = $link->target_field_id === null ? 'id' : $target->fields->firstWhere('id', $link->target_field_id)?->name;

                if ($field === null || ! AppInterfaces::fieldShared($target, $field)) {
                    throw new BusinessRuleException(__('apps.errors.cross_app_link'));
                }
            }

            $link->app_id = $source;
        });
    }

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
