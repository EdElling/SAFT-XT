<?php

declare(strict_types=1);

namespace App\Reports;

use App\DynamicData\Schema\FieldType;
use App\Support\AppLocale;
use App\Support\Translations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/** Anzeige von Ergebniswerten in der aktuellen Sprache (Tabelle, Kreuztabelle, Diagrammachsen). */
final class ValueFormatter
{
    public function format(mixed $value, ReportColumn $column): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $field = $column->field;

        // Verknüpfungsfeld mit Anzeigefeldern: Name statt Schlüssel.
        if ($column->aggregate === null && $column->display !== null) {
            return $column->display[(string) $value] ?? (string) $value;
        }

        if ($column->aggregate === null && $field?->bucket !== null) {
            return $this->bucket((string) $value, $field->bucket);
        }

        return match ($column->type()) {
            FieldType::Boolean => (bool) $value ? __('common.yes') : __('common.no'),
            FieldType::Integer => ($column->aggregate === null && $field?->type() === FieldType::Relation) || ! self::groupsDigits($column)
                ? (string) (int) $value
                : Number::format((int) $value, locale: AppLocale::numberLocale()),
            FieldType::Decimal => Number::format((float) $value, precision: $this->decimals($column), locale: AppLocale::numberLocale()),
            FieldType::Date => CarbonImmutable::parse((string) $value)->isoFormat('L'),
            FieldType::DateTime => CarbonImmutable::parse((string) $value)->isoFormat('L LT'),
            FieldType::Select => $this->option((string) $value, $column),
            default => (string) $value,
        };
    }

    /** Datums-Stufe lesbar: „März 2026“, „Q1 2026“, „KW 10/2026“. */
    private function bucket(string $value, string $bucket): string
    {
        return match ($bucket) {
            'year' => $value,
            'quarter' => str_replace('-', ' ', substr($value, 5)).' '.substr($value, 0, 4),
            'month' => CarbonImmutable::createFromFormat('!Y-m', $value)?->locale(app()->getLocale())->isoFormat('MMMM YYYY') ?? $value,
            'week' => __('reports.week', [
                'week' => CarbonImmutable::parse($value)->isoWeek(),
                'year' => CarbonImmutable::parse($value)->isoWeekYear(),
            ]),
            default => CarbonImmutable::parse($value)->isoFormat('L'),
        };
    }

    private function option(string $value, ReportColumn $column): string
    {
        foreach ((array) ($column->field?->field->optionsArray()['options'] ?? []) as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                return Translations::pick($option['label'] ?? null, $value);
            }
        }

        return $value;
    }

    /**
     * Tausendertrennzeichen? Nein für Felder wie „Jahr“ – auch bei Minimum/Maximum, nicht aber bei
     * Anzahl, Summe oder Durchschnitt (das sind wieder Mengen).
     */
    public static function groupsDigits(ReportColumn $column): bool
    {
        return ! (in_array($column->aggregate, [null, 'min', 'max'], true) && $column->field !== null && ! $column->field->field->groupsDigits());
    }

    /** Nachkommastellen: wie das Feld, beim Durchschnitt mindestens zwei. */
    public function decimals(ReportColumn $column): int
    {
        if ($column->computed && $column->scale !== null) {
            return $column->scale;
        }

        $scale = (int) ($column->field?->field->optionsArray()['scale'] ?? 0);

        return $column->aggregate === 'avg' ? max(2, $scale) : $scale;
    }
}
