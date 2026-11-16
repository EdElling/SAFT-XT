<?php

declare(strict_types=1);

namespace App\DynamicData\Schema;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\Printer;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\NowDefault;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Validation\FieldRuleCheck;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Support\Translations;

/**
 * Prüft und normalisiert Feld-Definitionen (Typ, typabhängige Optionen, Validierung).
 *
 * Validierung ist bewusst strukturiert (feste Schlüssel je Typ) statt freier
 * Laravel-Regeln: Regeln wie "exists:public.users,email" würden Systemtabellen
 * abfragbar machen, freie Regex-Ausdrücke ReDoS ermöglichen.
 */
final class FieldDefinitionNormalizer
{
    public const int MAX_STRING_LENGTH = 10_000;

    public const int MAX_SELECT_OPTIONS = 500;

    /** @var array<string, list<string>> erlaubte Validierungsschlüssel je Typ */
    private const array RULE_KEYS = [
        'string' => ['min_length', 'max_length', 'email', 'url', FieldRuleCheck::OPTION],
        'text' => ['min_length', 'max_length', FieldRuleCheck::OPTION],
        'integer' => ['min', 'max', FieldRuleCheck::OPTION],
        'decimal' => ['min', 'max', FieldRuleCheck::OPTION],
    ];

    public function __construct(private readonly IdentifierValidator $identifiers) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> Attribute für CustomField
     */
    public function normalize(array $data, ?CustomTable $owner = null): array
    {
        $name = (string) ($data['name'] ?? '');
        $this->identifiers->assertValidColumnName($name);

        $type = $data['type'] instanceof FieldType ? $data['type'] : FieldType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            $this->fail(__('tables.errors.unknown_type', ['type' => $data['type'] ?? '']));
        }

        $label = Translations::clean($data['label'] ?? null);

        return [
            'name' => $name,
            'label' => Translations::isEmpty($label) ? $name : $label,
            'type' => $type,
            'options' => $type === FieldType::Computed
                ? $this->computedOptions((array) ($data['options'] ?? []), $owner, $name)
                : $this->options($type, (array) ($data['options'] ?? []), $owner),
            'validation_rules' => $this->rules($type, (array) ($data['validation_rules'] ?? [])),
            // Berechnete Felder füllt SAFT; Pflicht und Eindeutigkeit gibt es dort nicht.
            'is_required' => (bool) ($data['is_required'] ?? false) && $type !== FieldType::Computed,
            'is_unique' => (bool) ($data['is_unique'] ?? false) && ! in_array($type, [FieldType::Text, FieldType::Boolean, FieldType::Computed], true),
            'is_searchable' => (bool) ($data['is_searchable'] ?? false) && in_array($type, [FieldType::String, FieldType::Text, FieldType::Select], true),
            'is_sortable' => (bool) ($data['is_sortable'] ?? false) && $type !== FieldType::Text,
            'show_in_table' => (bool) ($data['show_in_table'] ?? true),
            'show_in_form' => (bool) ($data['show_in_form'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function options(FieldType $type, array $options, ?CustomTable $owner): array
    {
        $typed = match ($type) {
            FieldType::String => ['length' => $this->int($options['length'] ?? 255, 1, self::MAX_STRING_LENGTH, __('tables.fields.length'))],
            FieldType::Decimal => $this->decimalOptions($options),
            FieldType::Select => ['options' => $this->selectOptions((array) ($options['options'] ?? []))],
            FieldType::Relation => $this->relationOptions($options, $owner),
            // Ohne Tausendertrennzeichen nur speichern, wenn ausdrücklich gewählt (Vorgabe: mit).
            FieldType::Integer => [...$this->suggestOptions($options, $owner), ...(array_key_exists(CustomField::GROUP_DIGITS, $options) && ! filter_var($options[CustomField::GROUP_DIGITS], FILTER_VALIDATE_BOOLEAN) ? [CustomField::GROUP_DIGITS => false] : [])],
            // Mit heute bzw. jetzt vorbelegen (nur gespeichert, wenn gewählt).
            FieldType::Date, FieldType::DateTime => filter_var($options[NowDefault::OPTION] ?? false, FILTER_VALIDATE_BOOLEAN) ? [NowDefault::OPTION => true] : [],
            default => [],
        };

        $copy = $this->copyOptions($type, $options, $owner);

        if ($copy !== [] && isset($typed[SequenceSuggester::OPTION])) {
            $this->fail(__('tables.errors.copy_and_suggest'));
        }

        if ($copy !== [] && isset($typed[NowDefault::OPTION])) {
            $this->fail(__('tables.errors.copy_and_now'));
        }

        return [...$typed, ...$copy];
    }

    /**
     * Vorgabe aus dem Master-Datensatz: „verknüpfung.feld“, nur passende Typen.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function copyOptions(FieldType $type, array $options, ?CustomTable $owner): array
    {
        $value = trim((string) ($options[CopySource::OPTION] ?? ''));

        if ($value === '' || $type === FieldType::Relation) {
            return [];
        }

        [$relationName, $sourceName] = CopySource::parse($value) ?? [null, null];
        $relation = $relationName === null ? null : $owner?->fields()->where('name', $relationName)->first();
        $target = $relation?->type === FieldType::Relation
            ? CustomTable::query()->designer()->where('name', (string) ($relation->optionsArray()['table'] ?? ''))->first()
            : null;
        $source = $target?->fields()->where('name', (string) $sourceName)->first();

        if (! $source instanceof CustomField || ! CopySource::compatible($type, $source->valueType())) {
            $this->fail(__('tables.errors.copy_source', ['source' => $value]));
        }

        return [CopySource::OPTION => $value];
    }

    /**
     * Berechnetes Feld: Ergebnisart (Ganz- oder Dezimalzahl mit Stellen) und Formel, geprüft gegen die
     * Felder der Tabelle, ohne Kreisbezug.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function computedOptions(array $options, ?CustomTable $owner, string $name): array
    {
        $result = ($options['result'] ?? 'decimal') === 'integer' ? 'integer' : 'decimal';
        $size = $result === 'decimal' ? $this->decimalOptions($options) : [];
        $formula = trim((string) ($options['formula'] ?? ''));

        $fields = $owner?->fields()->get()->keyBy('name')->all() ?? [];
        $compiled = app(FormulaAnalyzer::class)->compile($formula, $fields, $name, $owner?->name);

        // Kreisbezug mit anderen berechneten Feldern der Tabelle?
        FormulaAnalyzer::assertNoCycle($fields, $name, $compiled['ast']);

        return ['result' => $result, ...$size, 'formula' => Printer::print($compiled['ast']), 'ast' => $compiled['ast']];
    }

    /**
     * Fortlaufender Vorschlag (höchster Wert + 1), optional je Wert einer Verknüpfung derselben Tabelle.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function suggestOptions(array $options, ?CustomTable $owner): array
    {
        if (! filter_var($options[SequenceSuggester::OPTION] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        $scope = trim((string) ($options[SequenceSuggester::SCOPE] ?? ''));

        if ($scope === '') {
            return [SequenceSuggester::OPTION => true];
        }

        $relation = $owner?->fields()->where('name', $scope)->first();

        if (! $relation instanceof CustomField || $relation->type !== FieldType::Relation) {
            $this->fail(__('tables.errors.suggest_scope', ['field' => $scope]));
        }

        return [SequenceSuggester::OPTION => true, SequenceSuggester::SCOPE => $scope];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{precision: int, scale: int}
     */
    private function decimalOptions(array $options): array
    {
        $precision = $this->int($options['precision'] ?? 12, 1, 38, __('tables.fields.precision'));
        $scale = $this->int($options['scale'] ?? 2, 0, $precision, __('tables.fields.scale'));

        return ['precision' => $precision, 'scale' => $scale];
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<array{value: string, label: string}>
     */
    private function selectOptions(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $value = trim((string) (is_array($item) ? ($item['value'] ?? '') : $item));
            $label = Translations::clean(is_array($item) ? ($item['label'] ?? null) : null);

            if ($value === '' || mb_strlen($value) > FieldType::SELECT_LENGTH) {
                $this->fail(__('tables.errors.option_length', ['max' => FieldType::SELECT_LENGTH]));
            }

            if (isset($result[$value])) {
                $this->fail(__('tables.errors.duplicate_option', ['value' => $value]));
            }

            $result[$value] = ['value' => $value, 'label' => Translations::isEmpty($label) ? $value : $label];
        }

        if ($result === [] || count($result) > self::MAX_SELECT_OPTIONS) {
            $this->fail(__('tables.errors.option_count', ['max' => self::MAX_SELECT_OPTIONS]));
        }

        return array_values($result);
    }

    /**
     * Ziel einer Verknüpfung: nur andere Tabellen des Table-Designers (nie Systemtabellen).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function relationOptions(array $options, ?CustomTable $owner): array
    {
        $tableName = (string) ($options['table'] ?? '');
        $displayFields = RelationDisplay::fields($options);

        $target = $owner !== null && $owner->name === $tableName
            ? $owner
            // Verknüpfungen nur zwischen Designer-Tabellen, nie zu angebundenen externen.
            : CustomTable::query()->designer()->where('name', $tableName)->first();

        if ($target === null) {
            $this->fail(__('tables.errors.unknown_target', ['table' => $tableName]));
        }

        if ($displayFields === []) {
            $this->fail(__('tables.errors.unknown_display_field', ['field' => '', 'table' => $tableName]));
        }

        foreach ($displayFields as $displayField) {
            $display = $target->fields()->where('name', $displayField)->first();

            if (! $display instanceof CustomField || $display->type === FieldType::Relation) {
                $this->fail(__('tables.errors.unknown_display_field', ['field' => $displayField, 'table' => $tableName]));
            }
        }

        return RelationDisplay::store(['table' => $tableName], $displayFields, $options['separator'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, int|float|bool|list<string>>
     */
    private function rules(FieldType $type, array $rules): array
    {
        $allowed = self::RULE_KEYS[$type->value] ?? [];
        $unknown = array_diff(array_keys(array_filter($rules, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false && $v !== [])), $allowed);

        if ($unknown !== []) {
            $this->fail(__('tables.errors.validation_not_allowed', ['type' => $type->label(), 'rules' => implode(', ', $unknown)]));
        }

        $normalized = [];

        foreach ($allowed as $key) {
            $value = $rules[$key] ?? null;

            if ($value === null || $value === '' || $value === false || $value === []) {
                continue;
            }

            $normalized[$key] = match ($key) {
                'email', 'url' => true,
                FieldRuleCheck::OPTION => $this->checks($value),
                'min_length', 'max_length' => $this->int($value, 0, self::MAX_STRING_LENGTH, __("tables.fields.{$key}")),
                default => is_numeric($value) ? $value + 0 : $this->fail(__('tables.errors.not_a_number', ['what' => __("tables.fields.{$key}")])),
            };
        }

        foreach ([['min', 'max'], ['min_length', 'max_length']] as [$min, $max]) {
            if (isset($normalized[$min], $normalized[$max]) && $normalized[$min] > $normalized[$max]) {
                $this->fail(__('tables.errors.min_above_max', ['min' => __("tables.fields.{$min}"), 'max' => __("tables.fields.{$max}")]));
            }
        }

        return $normalized;
    }

    /**
     * Prüfregeln aus Plugins: Schlüssel „<plugin-id>.<regel>“. Ob das Plugin installiert ist, spielt keine Rolle –
     * fehlt es, wird die Regel übersprungen.
     *
     * @return list<string>
     */
    private function checks(mixed $value): array
    {
        $checks = array_values(array_unique(array_map('strval', (array) $value)));

        foreach ($checks as $check) {
            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*\.[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $check) !== 1) {
                $this->fail(__('tables.errors.invalid_check', ['check' => $check]));
            }
        }

        return $checks;
    }

    private function int(mixed $value, int $min, int $max, string $what): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
            $this->fail(__('tables.errors.integer_between', ['what' => $what, 'min' => $min, 'max' => $max]));
        }

        return (int) $value;
    }

    private function fail(string $message): never
    {
        throw new BusinessRuleException($message, 'dynamic.invalid_field');
    }
}
