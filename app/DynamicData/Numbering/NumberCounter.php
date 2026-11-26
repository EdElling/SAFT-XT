<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\User;

/**
 * Zählerstände eines Nummernkreises ansehen und setzen (B-135, N2; je Gruppe N4). Erhöhen geht jederzeit (z. B. „Rechnungen 2027
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
     * Je Periode (und Gruppe, N4): Zählerstand und höchste vorhandene Nummer (nach dem Muster), sortiert. Schlüssel
     * wie NumberAnalysis::key(), z. B. „2026“ bzw. „V 2026“.
     *
     * @return array<string, array{counter: int|null, highest: int|null}>
     */
    public function overview(CustomField $field): array
    {
        $counters = [];

        foreach ($this->sequences->counters((int) $field->id) as $row) {
            $counters[NumberAnalysis::key($row['period'], $row['group'])] = $row['value'];
        }

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
     * @param  string  $group  Kurzzeichen der Gruppe (N4); ohne Gruppenfeld NO_GROUP
     *
     * @throws BusinessRuleException
     */
    public function set(CustomField $field, string $period, int $value, bool $confirmed, ?User $user = null, string $group = NumberSequences::NO_GROUP): void
    {
        if ($field->type !== FieldType::Number) {
            throw new BusinessRuleException(__('numbering.counter.not_number', ['field' => $field->name]), 'dynamic.number_counter');
        }

        $pattern = NumberPattern::fromOptions($field->optionsArray());
        $period = trim($period);

        if (! self::validPeriod($pattern->reset, $period)) {
            throw new BusinessRuleException(__("numbering.counter.period_{$pattern->reset}", ['period' => $period]), 'dynamic.number_counter');
        }

        $group = trim($group);

        if ($pattern->grouped() ? ! in_array($group, (array) $pattern->groups, true) : $group !== NumberSequences::NO_GROUP) {
            throw new BusinessRuleException(__('numbering.counter.group_unknown', ['group' => $group]), 'dynamic.number_counter');
        }

        if ($value < 0) {
            throw new BusinessRuleException(__('numbering.counter.negative'), 'dynamic.number_counter');
        }

        $current = $this->sequences->current((int) $field->id, $period, $group) ?? $pattern->start - 1;

        if ($value < $current) {
            $highest = $this->highest($field)[NumberAnalysis::key($period, $group)] ?? 0;

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

        $before = $this->sequences->set((int) $field->id, $period, $value, $group);
        $label = __('numbering.counter.log_attribute', ['period' => NumberAnalysis::key($period, $group)]);

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

    /** @return array<string, int> Gruppe und Periode (NumberAnalysis::key) => höchste vorhandene Nummer */
    private function highest(CustomField $field): array
    {
        return $this->conversion->analyze($field->customTable, $field->name, NumberPattern::fromOptions($field->optionsArray()), FieldType::Number)->highest;
    }
}
