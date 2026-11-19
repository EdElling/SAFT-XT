<?php

declare(strict_types=1);

namespace App\DynamicData\Assistant;

use App\DynamicData\Rules\RuleFieldTypes;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\Models\CustomTable;
use InvalidArgumentException;

/**
 * Regeln in Textform für den Tabellen-Assistenten (B-110). Kurzer Text statt verschachtelter Struktur hält das
 * Antwortschema unter der Grammatik-Grenze von Anthropic.
 *
 * - Feldregeln: „min=1; max=3; email“ → ['min' => '1', 'max' => '3', 'email' => true]; „max=“ entfernt die Regel
 *   (null). Geprüft werden die Werte danach vom FieldDefinitionNormalizer wie bei der Eingabe von Hand.
 * - Tabellenregeln: „ende >= beginn“ oder „menge > 0“ → Formularzeile für TableRuleNormalizer.
 */
final class RuleText
{
    /** Feldregeln, die der Assistent setzen darf (ohne Plugin-Prüfungen). */
    public const array FIELD_KEYS = ['min', 'max', 'min_length', 'max_length', 'email', 'url'];

    private const array FLAGS = ['email', 'url'];

    /** Schreibweisen der Vergleiche → gespeicherter Operator. */
    private const array OPERATORS = ['<=' => '<=', '>=' => '>=', '<>' => '<>', '!=' => '<>', '≠' => '<>', '≤' => '<=', '≥' => '>=', '=' => '=', '<' => '<', '>' => '>'];

    /**
     * @return array<string, string|true|null> Schlüssel => Wert (null = entfernen)
     *
     * @throws InvalidArgumentException
     */
    public static function fieldRules(string $text): array
    {
        $rules = [];

        foreach (preg_split('/[;,]/', $text) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $pieces = explode('=', $part, 2);
            $key = strtolower(trim($pieces[0]));
            $value = isset($pieces[1]) ? trim($pieces[1]) : null;

            if (! in_array($key, self::FIELD_KEYS, true)) {
                throw new InvalidArgumentException($key);
            }

            if (in_array($key, self::FLAGS, true)) {
                $rules[$key] = ($value === null || ! in_array(strtolower($value), ['', '0', 'false', 'no'], true)) ? true : null;

                continue;
            }

            $rules[$key] = $value === null || $value === '' ? null : $value;
        }

        return $rules;
    }

    /**
     * Bestehende Feldregeln mit den Änderungen aus dem Text; leere Werte fallen weg.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, string|true|null>  $changes
     * @return array<string, mixed>
     */
    public static function merge(array $current, array $changes): array
    {
        return array_filter([...$current, ...$changes], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false);
    }

    /**
     * Tabellenregel als Formularzeile (TableRuleNormalizer); rechts ein Feld der Tabelle oder ein fester Wert.
     *
     * @throws InvalidArgumentException
     */
    public static function tableRow(string $text, CustomTable $table): array
    {
        $operators = implode('|', array_map(static fn (string $op): string => preg_quote($op, '/'), array_keys(self::OPERATORS)));

        if (preg_match('/^\s*([a-z][a-z0-9_]*)\s*('.$operators.')\s*(.+?)\s*$/u', $text, $m) !== 1) {
            throw new InvalidArgumentException($text);
        }

        [, $left, $operator, $right] = $m;
        $right = trim($right, " \t\"'“”„");
        $fields = $table->fields->keyBy('name');
        $isDate = RuleFieldTypes::group($fields->get($left)?->type) === RuleFieldTypes::DATE;

        $row = ['left' => $left, 'operator' => self::OPERATORS[$operator], 'when_filled' => true, 'message' => []];

        if ($fields->has($right)) {
            return [...$row, 'right_kind' => TableRuleNormalizer::KIND_FIELD, 'right_field' => $right, 'right_value' => null, 'right_date' => null];
        }

        return [...$row, 'right_kind' => TableRuleNormalizer::KIND_VALUE, 'right_field' => null,
            'right_value' => $isDate ? null : $right, 'right_date' => $isDate ? $right : null];
    }

    /** „Maximum 3, E-Mail-Adresse“ bzw. „Maximum entfernt“ für die Vorschau. */
    public static function describeField(array $changes): string
    {
        $parts = [];

        foreach ($changes as $key => $value) {
            $label = __("tables.fields.{$key}");
            $parts[] = match (true) {
                $value === null => __('tables_assistant.summary.rule_removed', ['rule' => $label]),
                $value === true => $label,
                default => $label.' '.$value,
            };
        }

        return implode(', ', $parts);
    }
}
