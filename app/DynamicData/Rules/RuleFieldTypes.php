<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Welche Felder sich in Regeln vergleichen lassen: Zahlen mit Zahlen, Datumswerte mit Datumswerten
 * (alle Vergleiche), Text und Auswahl sowie Ja/Nein nur auf Gleichheit. Verknüpfungen und langer
 * Text bleiben außen vor. Auch für spätere berechnete Felder gedacht (z. B. rechenbare Felder).
 */
final class RuleFieldTypes
{
    public const string NUMBER = 'number';

    public const string DATE = 'date';

    public const string TEXT = 'text';

    public const string BOOLEAN = 'boolean';

    private const array ORDERED = ['=', '<>', '<', '<=', '>', '>='];

    private const array EQUALITY = ['=', '<>'];

    public static function group(?FieldType $type): ?string
    {
        return match ($type) {
            FieldType::Integer, FieldType::Decimal, FieldType::Computed => self::NUMBER,
            FieldType::Date, FieldType::DateTime => self::DATE,
            FieldType::String, FieldType::Select => self::TEXT,
            FieldType::Boolean => self::BOOLEAN,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function operators(?string $group): array
    {
        return match ($group) {
            self::NUMBER, self::DATE => self::ORDERED,
            self::TEXT, self::BOOLEAN => self::EQUALITY,
            default => [],
        };
    }

    /**
     * Felder, die in Regeln vorkommen können.
     *
     * @return array<string, string> Name => Bezeichnung
     */
    public static function fieldOptions(CustomTable $table, ?string $group = null, ?string $except = null): array
    {
        return $table->fields
            ->filter(fn (CustomField $f): bool => $f->is_active && self::group($f->type) !== null
                && ($group === null || self::group($f->type) === $group) && $f->name !== $except)
            ->mapWithKeys(fn (CustomField $f): array => [$f->name => $f->label.' ('.$f->name.')'])
            ->all();
    }
}
