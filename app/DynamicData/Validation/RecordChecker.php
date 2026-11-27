<?php

declare(strict_types=1);

namespace App\DynamicData\Validation;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Formula\Evaluator;
use App\DynamicData\Rules\RuleEvaluator;
use App\DynamicData\Rules\TableRuleValidator;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\TableDefinition;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Prüfungen eines Datensatzes einer Designer-Tabelle wie beim Erfassen – ausserhalb eines Formulars (Datenimport,
 * Programmierschnittstelle für Plugins): Pflicht, Länge, Minimum/Maximum, E-Mail/URL, eindeutig, auf Wunsch
 * Auswahlwerte und vorhandene Verknüpfungen, dazu die Regeln der Tabelle.
 */
final class RecordChecker
{
    /** @var array<string, TableDefinition|null> */
    private array $definitions = [];

    public function __construct(
        private readonly DynamicDataNamespace $namespace,
        private readonly RuleEvaluator $rules,
    ) {}

    /**
     * Felder, die sich mit Werten füllen lassen: aktiv und nicht berechnet.
     *
     * @return list<CustomField>
     */
    public function fields(CustomTable $table): array
    {
        return $table->fields->where('is_active', true)->sortBy('sort_order')
            // Berechnete Felder füllt SAFT; Dateien kommen nur über das Formular (hochladen), nicht als Wert.
            ->reject(fn (CustomField $field): bool => in_array($field->type, [FieldType::Computed, FieldType::File], true))
            ->values()
            ->all();
    }

    /** Pflichtfelder, die SAFT selbst füllen kann (fortlaufend, Übernehmen aus). */
    public function hasDefault(CustomTable $table, CustomField $field): bool
    {
        $definition = $this->definitions[$table->name] ??= TableDefinition::fromCustomTable($table, $this->namespace->qualify($table->name));

        return array_key_exists($field->name, $definition?->sequences ?? []) || array_key_exists($field->name, $definition?->copies ?? []);
    }

    /**
     * @param  array<string, mixed>  $values  neue Werte (beim Ändern nur die geänderten)
     * @param  DynamicRecord|null  $existing  beim Ändern: der gespeicherte Datensatz (eindeutig ohne sich selbst, Regeln mit allen Werten)
     * @param  bool  $complete  fehlende Pflichtfelder melden (Anlegen über die Schnittstelle); sonst nur leer übergebene
     * @param  bool  $references  Auswahlwerte und Verknüpfungen prüfen (der Import wandelt sie vorher selbst um)
     * @param  array<string, array<string, true>>  $unique  bereits vergebene Werte derselben Reihe (Import einer Datei)
     * @return list<array{0: CustomField|null, 1: string}> Feld (oder null für die ganze Zeile) und Meldung
     */
    public function check(CustomTable $table, array $values, ?DynamicRecord $existing = null, bool $complete = false, bool $references = false, array &$unique = []): array
    {
        $problems = [];

        foreach ($this->fields($table) as $field) {
            $given = array_key_exists($field->name, $values);
            $value = $values[$field->name] ?? null;

            if ($value === null || $value === '') {
                $mustCheck = $given || ($complete && $existing === null);

                if ($field->is_required && $mustCheck && ! $this->hasDefault($table, $field)) {
                    $problems[] = [$field, __('import.records.required')];
                }

                continue;
            }

            $rules = (array) ($field->validation_rules ?? []);
            $options = $field->optionsArray();
            $length = mb_strlen((string) $value);

            // Auch Nummernkreise (Text, Länge NumberPattern::COLUMN_LENGTH), z. B. beim Import von Altdaten.
            if ($field->valueType() === FieldType::String && $field->type !== FieldType::Select && $length > (int) ($options['length'] ?? 255)) {
                $problems[] = [$field, __('import.records.too_long', ['max' => (int) ($options['length'] ?? 255)])];
            }

            if (isset($rules['max_length']) && $length > (int) $rules['max_length']) {
                $problems[] = [$field, __('import.records.too_long', ['max' => (int) $rules['max_length']])];
            }

            if (isset($rules['min_length']) && $length < (int) $rules['min_length']) {
                $problems[] = [$field, __('import.records.too_short', ['min' => (int) $rules['min_length']])];
            }

            if (in_array($field->type, [FieldType::Integer, FieldType::Decimal], true)) {
                if (! is_numeric($value)) {
                    $problems[] = [$field, __('import.records.not_number', ['value' => $value])];

                    continue;
                }

                if (($tooLarge = self::outOfRange($field, (string) $value, $options)) !== null) {
                    $problems[] = [$field, $tooLarge];

                    continue;
                }

                if (isset($rules['min']) && (float) $value < (float) $rules['min']) {
                    $problems[] = [$field, __('import.records.below_min', ['min' => $rules['min']])];
                }

                if (isset($rules['max']) && (float) $value > (float) $rules['max']) {
                    $problems[] = [$field, __('import.records.above_max', ['max' => $rules['max']])];
                }
            }

            if (($rules['email'] ?? false) && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = [$field, __('import.records.not_email')];
            }

            if (($rules['url'] ?? false) && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $problems[] = [$field, __('import.records.not_url')];
            }

            foreach (app(FieldRuleCheck::class)->messages($field, $value) as $message) {
                $problems[] = [$field, $message];
            }

            if ($references && $field->type === FieldType::Select) {
                $allowed = array_map('strval', array_column(array_filter((array) ($options['options'] ?? []), 'is_array'), 'value'));

                if (! in_array((string) $value, $allowed, true)) {
                    $problems[] = [$field, __('import.records.not_choice', ['value' => $value])];
                }
            }

            if ($references && $field->type === FieldType::Relation
                && ! DynamicRecord::forTableName((string) ($options['table'] ?? ''))->newQuery()->whereKey($value)->exists()) {
                $problems[] = [$field, __('import.records.not_found', ['value' => $value])];
            }

            if ($field->is_unique) {
                $key = mb_strtolower((string) $value);

                if (isset($unique[$field->name][$key])) {
                    $problems[] = [$field, __('import.records.duplicate_in_file', ['value' => $value])];
                } elseif (DynamicRecord::forTableName($table->name)->newQuery()->where($field->name, $value)
                    ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
                    ->exists()) {
                    $problems[] = [$field, __('import.records.duplicate', ['value' => $value])];
                }

                $unique[$field->name][$key] = true;
            }
        }

        // Regeln der Tabelle (z. B. Lieferdatum ≥ Bestelldatum) mit allen Werten des Datensatzes.
        $all = [...($existing?->attributesToArray() ?? []), ...$values];
        $byName = $table->fields->keyBy('name')->all();

        foreach ($table->tableRules() as $rule) {
            if ($this->rules->passes($rule, fn (string $name): mixed => $all[$name] ?? null) === false) {
                $left = $rule->left->fieldName();
                $problems[] = [$left === null ? null : ($byName[$left] ?? null), TableRuleValidator::message($rule, $byName)];
            }
        }

        return $problems;
    }

    /**
     * Passt die Zahl in die Spalte? Dezimalzahl: höchstens precision − scale Stellen vor dem Komma (die Nachkommastellen
     * rundet die Datenbank); Ganzzahl: Bereich von bigint. Sonst lehnte die Datenbank erst beim Speichern ab.
     *
     * @param  array<string, mixed>  $options
     */
    private static function outOfRange(CustomField $field, string $value, array $options): ?string
    {
        $number = Evaluator::decimal($value);

        if ($number === null) {
            return null;
        }

        $integer = ltrim(explode('.', ltrim($number, '-'))[0], '0');

        if ($field->type === FieldType::Decimal) {
            $digits = (int) ($options['precision'] ?? 12) - (int) ($options['scale'] ?? 2);
            // Was beim Runden auf die Nachkommastellen eine Stelle dazubekommt (99.995 → 100.00), zählt mit.
            $rounded = ltrim(explode('.', ltrim((string) Evaluator::round($number, (int) ($options['scale'] ?? 2)), '-'))[0], '0');

            return max(strlen($integer), strlen($rounded)) > $digits
                ? __('import.records.too_many_digits', ['max' => $digits])
                : null;
        }

        $limit = str_starts_with($number, '-') ? '9223372036854775808' : '9223372036854775807';

        return strlen($integer) > strlen($limit) || (strlen($integer) === strlen($limit) && strcmp($integer, $limit) > 0)
            ? __('import.records.integer_range')
            : null;
    }
}
