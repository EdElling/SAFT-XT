<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\User;

/**
 * Zählerstände eines Nummernkreises ansehen und setzen (B-135, N2). Erhöhen geht jederzeit (z. B. „Rechnungen 2027
 * beginnen bei 1000“), senken nur, wenn keine vorhandene Nummer der Periode höher ist (auch im Papierkorb), und nur mit
 * Bestätigung. Jede Änderung steht im Änderungsprotokoll des Felds.
 */
final readonly class NumberCounter
{
    public function __construct(
        private NumberSequences $sequences,
        private NumberConversion $conversion,
    ) {}

    /**
     * Je Periode: Zählerstand und höchste vorhandene Nummer (nach dem Muster), nach Periode sortiert.
     *
     * @return array<string, array{counter: int|null, highest: int|null}>
     */
    public function overview(CustomField $field): array
    {
        $counters = $this->sequences->periods((int) $field->id);
        $highest = $this->highest($field);
        $rows = [];

        foreach (array_unique([...array_keys($counters), ...array_keys($highest)]) as $period) {
            $rows[(string) $period] = ['counter' => $counters[$period] ?? null, 'highest' => $highest[$period] ?? null];
        }

        ksort($rows, SORT_STRING);

        return $rows;
    }

    /**
     * @param  string  $period  „-“ ohne Neubeginn, „2027“ jährlich, „2027-01“ monatlich
     * @param  int  $value  letzter vergebener Wert; die nächste Nummer ist $value + 1
     *
     * @throws BusinessRuleException
     */
    public function set(CustomField $field, string $period, int $value, bool $confirmed, ?User $user = null): void
    {
        if ($field->type !== FieldType::Number) {
            throw new BusinessRuleException(__('numbering.counter.not_number', ['field' => $field->name]), 'dynamic.number_counter');
        }

        $pattern = NumberPattern::fromOptions($field->optionsArray());
        $period = trim($period);

        if (! self::validPeriod($pattern->reset, $period)) {
            throw new BusinessRuleException(__("numbering.counter.period_{$pattern->reset}", ['period' => $period]), 'dynamic.number_counter');
        }

        if ($value < 0) {
            throw new BusinessRuleException(__('numbering.counter.negative'), 'dynamic.number_counter');
        }

        $current = $this->sequences->current((int) $field->id, $period) ?? $pattern->start - 1;

        if ($value < $current) {
            $highest = $this->highest($field)[$period] ?? 0;

            if ($value < $highest) {
                throw new BusinessRuleException(__('numbering.counter.conflict', ['value' => $value, 'highest' => $highest]), 'dynamic.number_counter_conflict');
            }

            if (! $confirmed) {
                throw new BusinessRuleException(__('numbering.counter.confirm_lower'), 'dynamic.number_counter_confirm');
            }
        }

        if ($value === $current) {
            return;
        }

        $before = $this->sequences->set((int) $field->id, $period, $value);
        $label = __('numbering.counter.log_attribute', ['period' => $period]);

        activity('custom_field')->performedOn($field)->causedBy($user ?? auth()->user())->event('number_counter')
            ->withChanges(['attributes' => [$label => $value], 'old' => [$label => $before]])
            ->log('number_counter');
    }

    /** Periode passend zum Neubeginn? */
    public static function validPeriod(string $reset, string $period): bool
    {
        return match ($reset) {
            'yearly' => preg_match('/^\d{4}$/', $period) === 1,
            'monthly' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1,
            default => $period === NumberPattern::NO_PERIOD,
        };
    }

    /** @return array<string, int> Periode => höchste vorhandene Nummer */
    private function highest(CustomField $field): array
    {
        return $this->conversion->analyze($field->customTable, $field->name, NumberPattern::fromOptions($field->optionsArray()), FieldType::Number)->highest;
    }
}
