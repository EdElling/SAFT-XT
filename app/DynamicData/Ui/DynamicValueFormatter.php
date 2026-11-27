<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\Files\RecordFiles;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\DynamicRecord;
use App\Support\AppLocale;
use App\Support\Translations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Lesbare Anzeige eines Feldwerts (Detailansicht).
 */
final class DynamicValueFormatter
{
    public static function display(CustomField $field, DynamicRecord $record): ?string
    {
        return self::raw($field, $record->getAttribute($field->name));
    }

    /**
     * Lesbare Anzeige eines Werts ohne Datensatz, z. B. eines Rohwerts aus dem Änderungsprotokoll (B-174). Verknüpfungen
     * schlägt $relation nach (Vorgabe: einzeln in der Zieltabelle).
     *
     * @param  (\Closure(CustomField, int|string): ?string)|null  $relation
     */
    public static function raw(CustomField $field, mixed $value, ?\Closure $relation = null): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $options = $field->optionsArray();

        return match ($field->valueType()) {
            FieldType::Boolean => filter_var($value, FILTER_VALIDATE_BOOL) ? __('common.yes') : __('common.no'),
            FieldType::Integer => $field->groupsDigits() ? Number::format((int) $value, locale: AppLocale::numberLocale()) : (string) (int) $value,
            FieldType::Decimal => Number::format((float) $value, (int) ($options['scale'] ?? 2), locale: AppLocale::numberLocale()),
            FieldType::Date => AppLocale::date(Carbon::parse($value)),
            FieldType::DateTime => AppLocale::dateTime(Carbon::parse($value)),
            FieldType::Select => (string) (Translations::pick(collect((array) ($options['options'] ?? []))->firstWhere('value', $value)['label'] ?? null, (string) $value)),
            FieldType::Relation => $relation !== null ? ($relation($field, $value) ?? '#'.$value) : self::relationLabel($options, $value),
            FieldType::File => implode(', ', RecordFiles::names(is_string($value) ? (json_decode($value, true) ?? []) : $value)),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function relationLabel(array $options, int|string $id): string
    {
        try {
            $label = RelationDisplay::label(
                DynamicRecord::forTableName((string) $options['table'])->newQuery()->withTrashed()->find($id),
                $options,
            );
        } catch (ModelNotFoundException) {
            $label = null;
        }

        return $label !== null ? (string) $label : '#'.$id;
    }
}
