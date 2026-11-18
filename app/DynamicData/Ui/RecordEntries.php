<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\Schema\FieldType;
use App\Filament\Support\FieldBlocks;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Schemas\Components\View;

/**
 * Lesende Anzeige der Felder eines Datensatzes (Kurzanzeige in der Kaskade, Popup) als Blöcke „Name | Wert“.
 */
final class RecordEntries
{
    /**
     * Felder als Blöcke „Name | Wert“ (siehe FieldBlocks).
     *
     * @param  bool  $compact  nur Felder "In Tabelle anzeigen" und Pflichtfelder
     */
    public static function blocks(CustomTable $table, DynamicRecord $record, bool $compact = false, int $maxBlocks = FieldBlocks::MAX_BLOCKS): View
    {
        return FieldBlocks::make($table->fields
            ->filter(fn (CustomField $f): bool => $f->is_active && (! $compact || $f->show_in_table || $f->is_required))
            ->map(fn (CustomField $f): array => [
                'label' => (string) $f->label,
                'value' => DynamicValueFormatter::display($f, $record),
                'numeric' => in_array($f->valueType(), [FieldType::Integer, FieldType::Decimal], true),
                // Dateien mit Vorschau und Links (alle, nicht nur drei wie in der Liste).
                'html' => $f->type === FieldType::File ? DynamicTableBuilder::fileSummary($record->getAttribute($f->name), limit: null) : null,
            ])
            ->values()
            ->all(), maxBlocks: $maxBlocks);
    }

    /** Kurzbezeichnung, z. B. "Bestellungen: B-2026-0007". */
    public static function title(CustomTable $table, DynamicRecord $record): string
    {
        $first = $table->fields->first(fn (CustomField $f): bool => $f->is_active && $f->show_in_table);
        $value = $first === null ? null : DynamicValueFormatter::display($first, $record);

        return $table->label.': '.($value ?? '#'.$record->getKey());
    }
}
