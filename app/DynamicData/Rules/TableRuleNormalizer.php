<?php

declare(strict_types=1);

namespace App\DynamicData\Rules;

use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Support\Translations;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Prüft eigene Regeln einer Tabelle (Table-Designer) und übersetzt zwischen Formularzeilen und der
 * gespeicherten Form (TableRule::toArray()).
 *
 * Formularzeile: left, operator, right_kind (field|value), right_field, right_value, right_date,
 * when_filled, message (Übersetzungen).
 */
final class TableRuleNormalizer
{
    public const string KIND_FIELD = 'field';

    public const string KIND_VALUE = 'value';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> gespeicherte Regeln (Herkunft „designer“)
     */
    public function normalize(CustomTable $table, array $rows): array
    {
        $fields = $table->fields->keyBy('name');
        $rules = [];

        foreach (array_values($rows) as $index => $row) {
            $rules[] = $this->rule($fields->all(), (array) $row, $index + 1)->toArray();
        }

        return $rules;
    }

    /**
     * @param  array<string, CustomField>  $fields
     * @param  array<string, mixed>  $row
     */
    private function rule(array $fields, array $row, int $number): TableRule
    {
        $left = $fields[(string) ($row['left'] ?? '')] ?? null;
        $group = RuleFieldTypes::group($left?->type);

        if ($left === null || $group === null) {
            $this->fail($number, __('tables.rules.errors.left'));
        }

        $operator = (string) ($row['operator'] ?? '');

        if (! in_array($operator, RuleFieldTypes::operators($group), true)) {
            $this->fail($number, __('tables.rules.errors.operator'));
        }

        if (($row['right_kind'] ?? self::KIND_FIELD) === self::KIND_FIELD) {
            $right = $fields[(string) ($row['right_field'] ?? '')] ?? null;

            if ($right === null || $right->name === $left->name || RuleFieldTypes::group($right->type) !== $group) {
                $this->fail($number, __('tables.rules.errors.right_field'));
            }

            $operand = Operand::field($right->name);
        } else {
            $operand = Operand::value($this->value($left, $group, $group === RuleFieldTypes::DATE ? ($row['right_date'] ?? null) : ($row['right_value'] ?? null), $number));
        }

        $message = Translations::clean($row['message'] ?? null, 255);

        return new TableRule(
            Operand::field($left->name),
            $operator,
            $operand,
            whenFilled: (bool) ($row['when_filled'] ?? true),
            source: TableRule::SOURCE_DESIGNER,
            message: Translations::isEmpty($message) ? null : $message,
        );
    }

    private function value(CustomField $field, string $group, mixed $value, int $number): string|int|float
    {
        if ($value === null || $value === '') {
            $this->fail($number, __('tables.rules.errors.value'));
        }

        try {
            return match ($group) {
                RuleFieldTypes::NUMBER => is_numeric($value)
                    ? ($field->type === FieldType::Integer ? (int) $value : (float) $value)
                    : throw new \InvalidArgumentException,
                RuleFieldTypes::DATE => Carbon::parse((string) $value)->format($field->type === FieldType::Date ? 'Y-m-d' : 'Y-m-d H:i:s'),
                RuleFieldTypes::BOOLEAN => in_array((string) $value, ['0', '1'], true) ? (int) $value : throw new \InvalidArgumentException,
                default => $this->text($field, (string) $value),
            };
        } catch (Throwable) {
            $this->fail($number, __('tables.rules.errors.value'));
        }
    }

    private function text(CustomField $field, string $value): string
    {
        $allowed = $field->type === FieldType::Select
            ? array_map(static fn (array $o): string => (string) $o['value'], (array) ($field->optionsArray()['options'] ?? []))
            : null;

        if (mb_strlen($value) > 255 || ($allowed !== null && ! in_array($value, $allowed, true))) {
            throw new \InvalidArgumentException;
        }

        return $value;
    }

    /**
     * Gespeicherte Regeln als Formularzeilen (nur eigene; Regeln aus der Datenbank gibt es hier nicht).
     *
     * @return list<array<string, mixed>>
     */
    public static function toRows(CustomTable $table): array
    {
        $rows = [];

        foreach ($table->tableRules() as $rule) {
            if ($rule->source !== TableRule::SOURCE_DESIGNER) {
                continue;
            }

            $isField = $rule->right->isField();
            $value = $isField ? null : $rule->right->value;
            $date = RuleFieldTypes::group($table->fields->firstWhere('name', $rule->left->fieldName())?->type) === RuleFieldTypes::DATE;

            $rows[] = [
                'left' => $rule->left->fieldName(),
                'operator' => $rule->operator,
                'right_kind' => $isField ? self::KIND_FIELD : self::KIND_VALUE,
                'right_field' => $rule->right->fieldName(),
                'right_value' => $date || $value === null ? null : (string) $value,
                'right_date' => $date ? $value : null,
                'when_filled' => $rule->whenFilled,
                'message' => $rule->message ?? [],
            ];
        }

        return $rows;
    }

    /** Kurzform, z. B. „Lieferdatum ≥ Bestelldatum“. */
    public static function describe(TableRule $rule, CustomTable $table): string
    {
        $label = static fn (?string $name): string => (string) ($table->fields->firstWhere('name', $name)?->label ?? $name);
        $symbol = ['=' => '=', '<>' => '≠', '<' => '<', '<=' => '≤', '>' => '>', '>=' => '≥'][$rule->operator];

        return $label($rule->left->fieldName()).' '.$symbol.' '.($rule->right->isField() ? $label($rule->right->fieldName()) : (string) $rule->right->value);
    }

    private function fail(int $number, string $message): never
    {
        throw new BusinessRuleException(__('tables.rules.errors.prefix', ['number' => $number, 'message' => $message]), 'dynamic.table_rule');
    }
}
