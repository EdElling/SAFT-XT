<?php

declare(strict_types=1);

namespace App\Reports;

use App\DynamicData\Schema\FieldType;
use App\Support\AppLocale;
use App\Support\Translations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/**
 * Beschreibt eine Auswertung in einem Satz der aktuellen Sprache, z. B.
 * „Summe Betrag je Monat und Kategorie; nur Status ungleich „storniert“; sortiert nach Summe Betrag absteigend“.
 * Hilft beim Prüfen, ob die Auswertung das Gewünschte tut (und später dem KI-Assistenten).
 */
final class ReportDescriber
{
    public function describe(ResolvedReport $report): string
    {
        $definition = $report->definition;
        $parts = [$definition->isGrouped() ? $this->grouped($report) : __('reports.describe.list', [
            'fields' => $this->join(array_map(fn (string $key): string => ($expression = $definition->expression($key)) !== null
                ? ReportResult::expressionLabel($report, $expression['label'], $expression['expr'])
                : $report->field($key)->label, $definition->shownKeys())),
        ])];

        if ($definition->filters !== []) {
            $parts[] = __('reports.describe.filters', ['filters' => $this->join(array_map(
                fn (array $filter): string => $this->filter($report->field($filter['field']), $filter['op'], $filter['value']),
                $definition->filters,
            ))]);
        }

        if (($sort = $definition->sort[0] ?? null) !== null) {
            $parts[] = __('reports.describe.sort', [
                'field' => $this->outputLabel($report, $sort['key']),
                'dir' => __('reports.ui.'.$sort['dir']),
            ]);
        }

        if ($definition->limit !== null) {
            $parts[] = __('reports.describe.limit', ['count' => Number::format($definition->limit, locale: AppLocale::numberLocale())]);
        }

        return implode('; ', $parts);
    }

    private function grouped(ResolvedReport $report): string
    {
        $definition = $report->definition;
        $measures = $definition->measures === []
            ? [__('reports.describe.records')]
            : array_map(fn (array $m): string => $this->measureLabel($report, $m), $definition->measures);
        $sentence = $this->join($measures);

        if ($definition->groups !== []) {
            $sentence = __('reports.describe.per', ['measures' => $sentence, 'groups' => $this->join(array_map(fn (string $key): string => $this->groupLabel($report->field($key)), $definition->groups))]);
        }

        if ($definition->columns !== []) {
            $sentence = __('reports.describe.split', ['sentence' => $sentence, 'column' => $this->groupLabel($report->field($definition->columns[0]))]);
        }

        return $sentence;
    }

    /** @param  array{key: string, field: string|null, agg: string, label: string|null}  $measure */
    private function measureLabel(ResolvedReport $report, array $measure): string
    {
        if ($measure['agg'] === ReportDefinition::CALC) {
            return ReportExpression::text((array) $measure['calc'], fn (string $key): string => ReportResult::measureLabels($report)[$key] ?? $key);
        }

        if (isset($measure['expr'])) {
            return __('reports.measure_label', ['agg' => __('reports.aggregates.'.$measure['agg']), 'field' => ReportResult::expressionLabel($report, null, (array) $measure['expr'])]);
        }

        if ($measure['field'] === null) {
            return __('reports.describe.records');
        }

        return __('reports.measure_label', ['agg' => __('reports.aggregates.'.$measure['agg']), 'field' => $report->field($measure['field'])->label]);
    }

    private function groupLabel(ResolvedField $field): string
    {
        // „Monat (Bestelldatum)“ statt nur „Bestelldatum“, wenn nach einer Stufe gruppiert wird.
        if ($field->bucket === null) {
            return $field->label;
        }

        $bucket = __('reports.buckets.'.$field->bucket);

        // Heißt das Feld schon wie die Stufe („Monat“), nicht „Monat (Monat)“.
        return mb_strtolower($field->label) === mb_strtolower($bucket) ? $field->label : $bucket.' ('.$field->label.')';
    }

    private function outputLabel(ResolvedReport $report, string $key): string
    {
        foreach ($report->definition->measures as $measure) {
            if ($measure['key'] === $key) {
                return $measure['label'] ?? $this->measureLabel($report, $measure);
            }
        }

        if (($expression = $report->definition->expression($key)) !== null) {
            return ReportResult::expressionLabel($report, $expression['label'], $expression['expr']);
        }

        return isset($report->fields[$key]) ? $report->field($key)->label : $key;
    }

    private function filter(ResolvedField $field, string $op, mixed $value): string
    {
        $values = array_values((array) $value);

        return match ($op) {
            'between' => __('reports.describe.between', ['field' => $field->label, 'from' => $this->value($field, $values[0] ?? null), 'to' => $this->value($field, $values[1] ?? null)]),
            'in' => __('reports.describe.in', ['field' => $field->label, 'values' => $this->join(array_map(fn (mixed $v): string => $this->value($field, $v), $values), 'or')]),
            'is_empty', 'not_empty', 'is_true', 'is_false', 'today', 'this_month', 'last_month', 'this_year', 'last_year' => __('reports.describe.state', ['field' => $field->label, 'op' => __('reports.operators.'.$op)]),
            'last_days' => __('reports.describe.last_days', ['field' => $field->label, 'days' => (int) $value]),
            default => __('reports.describe.compare', ['field' => $field->label, 'op' => __('reports.operators.'.$op), 'value' => $this->value($field, $value)]),
        };
    }

    /** Filterwert lesbar und in Anführungszeichen der Sprache. */
    private function value(ResolvedField $field, mixed $value): string
    {
        $text = match ($field->type()) {
            FieldType::Date, FieldType::DateTime => filled($value) ? CarbonImmutable::parse((string) $value)->isoFormat('L') : '',
            FieldType::Integer, FieldType::Decimal => ! is_numeric($value) ? (string) $value
                : (! $field->field->groupsDigits() ? (string) ($value + 0) : Number::format($value + 0, locale: AppLocale::numberLocale())),
            FieldType::Select => $this->option($field, (string) $value),
            default => (string) $value,
        };

        return __('reports.describe.quoted', ['value' => $text]);
    }

    private function option(ResolvedField $field, string $value): string
    {
        foreach ((array) ($field->field->optionsArray()['options'] ?? []) as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                return Translations::pick($option['label'] ?? null, $value);
            }
        }

        return $value;
    }

    /** @param  list<string>  $items */
    private function join(array $items, string $word = 'and'): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' '.__('reports.describe.'.$word).' '.$last;
    }
}
