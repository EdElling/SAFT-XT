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
        $value = $record->getAttribute($field->name);

        if ($value === null || $value === '') {
            return null;
        }

        $options = $field->optionsArray();

        return match ($field->valueType()) {
            FieldType::Boolean => $value ? __('common.yes') : __('common.no'),
            FieldType::Integer => $field->groupsDigits() ? Number::format((int) $value, locale: AppLocale::numberLocale()) : (string) (int) $value,
            FieldType::Decimal => Number::format((float) $value, (int) ($options['scale'] ?? 2), locale: AppLocale::numberLocale()),
            FieldType::Date => AppLocale::date(Carbon::parse($value)),
            FieldType::DateTime => AppLocale::dateTime(Carbon::parse($value)),
            FieldType::Select => (string) (Translations::pick(collect((array) ($options['options'] ?? []))->firstWhere('value', $value)['label'] ?? null, (string) $value)),
            FieldType::Relation => self::relationLabel($options, $value),
            FieldType::File => implode(', ', RecordFiles::names($value)),
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
