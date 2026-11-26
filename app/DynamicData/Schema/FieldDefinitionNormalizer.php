<?php

declare(strict_types=1);

namespace App\DynamicData\Schema;

use App\Apps\AppInterfaces;
use App\DynamicData\Files\FileKinds;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Printer;
use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberPattern;
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
    /** Option einer Verknüpfung: beim Kopieren des übergeordneten Datensatzes mitkopieren (B-123). */
    public const string COPY_WITH_PARENT = 'copy_with_parent';

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
                : $this->options($type, (array) ($data['options'] ?? []), $owner, $name),
            'validation_rules' => $this->rules($type, (array) ($data['validation_rules'] ?? [])),
            // Berechnete Felder und Nummernkreise füllt SAFT: keine Pflicht. Nummern sind immer eindeutig.
            'is_required' => (bool) ($data['is_required'] ?? false) && ! $type->isGenerated(),
            'is_unique' => $type === FieldType::Number || ((bool) ($data['is_unique'] ?? false) && ! in_array($type, [FieldType::Text, FieldType::Boolean, FieldType::Computed, FieldType::File], true)),
            'is_searchable' => (bool) ($data['is_searchable'] ?? false) && in_array($type, [FieldType::String, FieldType::Text, FieldType::Select, FieldType::Number], true),
            'is_sortable' => (bool) ($data['is_sortable'] ?? false) && ! in_array($type, [FieldType::Text, FieldType::File], true),
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
    private function options(FieldType $type, array $options, ?CustomTable $owner, string $name = ''): array
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
            FieldType::File => $this->fileOptions($options),
            FieldType::Number => $this->numberOptions($options, $owner, $name),
            default => [],
        };

        // Dateien und Nummernkreise übernehmen keine Werte aus dem Master-Datensatz.
        if ($type === FieldType::File || $type === FieldType::Number) {
            return $typed;
        }

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

        // Aus einer Tabelle einer anderen App nur freigegebene Felder (Schnittstelle).
        if ($owner !== null && AppInterfaces::foreign($target, $owner->app_id) && ! $source->is_shared) {
            $this->fail(__('apps.errors.not_shared_field', ['field' => (string) $sourceName, 'table' => (string) $target->label]));
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
        $this->assertFormulaWithinInterfaces($compiled['ast'], $owner);

        return ['result' => $result, ...$size, 'formula' => Printer::print($compiled['ast']), 'ast' => $compiled['ast']];
    }

    /**
     * Formeln über App-Grenzen (B-107, Schnittstellen): keine Summen und Zählungen über Kind-Tabellen einer anderen App.
     * Felder einer verknüpften Eltern-Tabelle liest eine Formel ohnehin nicht (nur innerhalb von Summen).
     *
     * @param  array<string, mixed>  $ast
     */
    private function assertFormulaWithinInterfaces(array $ast, ?CustomTable $owner): void
    {
        if ($owner === null) {
            return;
        }

        foreach (FormulaAnalyzer::aggregates($ast) as $aggregate) {
            $child = CustomTable::query()->where('name', (string) $aggregate['table'])->first();

            if ($child !== null && AppInterfaces::foreign($child, $owner->app_id)) {
                $this->fail(__('apps.errors.cross_app_aggregate', ['table' => (string) $child->label]));
            }
        }

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
     * Nummernkreis (B-135): Muster, Neubeginn, Bezugsdatum (leer = Anlegedatum, sonst ein Pflichtfeld vom Typ Datum
     * bzw. Datum/Zeit derselben Tabelle), Beginn des Geschäftsjahrs (nur jährlich) und Startwert.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function numberOptions(array $options, ?CustomTable $owner, string $name): array
    {
        $reset = (string) ($options[NumberPattern::RESET] ?? 'never');
        $fiscal = $reset === 'yearly' ? $this->int($options[NumberPattern::FISCAL_START] ?? 1, 1, 12, __('numbering.fields.fiscal_start')) : 1;
        $start = $this->int($options[NumberPattern::START] ?? 1, 1, 1_000_000_000, __('numbering.fields.start'));
        [$groupField, $codes] = $this->numberGroup($options, $owner);
        $pattern = NumberPattern::make((string) ($options[NumberPattern::PATTERN] ?? ''), $reset, $fiscal, $start, $groupField === '' ? null : array_values($codes));
        $dateField = trim((string) ($options[NumberPattern::DATE_FIELD] ?? ''));

        if ($dateField !== '') {
            $date = $owner?->fields()->where('name', $dateField)->first();

            if (! $date instanceof CustomField || ! in_array($date->type, [FieldType::Date, FieldType::DateTime], true)) {
                $this->fail(__('numbering.errors.date_field', ['field' => $dateField]));
            }

            if (! $date->is_required) {
                $this->fail(__('numbering.errors.date_field_required', ['field' => $dateField]));
            }
        }

        // „Vergeben, wenn“ (N3): Bedingung in der Formelsprache, nur gespeicherte Felder desselben Datensatzes.
        try {
            $condition = NumberCondition::compile((string) ($options[NumberCondition::OPTION] ?? ''), $owner?->fields()->get()->keyBy('name')->all() ?? [], $name, $owner?->name);
        } catch (FormulaException $e) {
            $this->fail(__('numbering.errors.condition', ['message' => $e->getMessage()]));
        }

        return [
            NumberPattern::PATTERN => $pattern->pattern,
            NumberPattern::RESET => $reset,
            ...($dateField === '' ? [] : [NumberPattern::DATE_FIELD => $dateField]),
            ...($reset === 'yearly' && $fiscal !== 1 ? [NumberPattern::FISCAL_START => $fiscal] : []),
            NumberPattern::START => $start,
            ...($groupField === '' ? [] : [NumberPattern::GROUP_FIELD => $groupField, NumberPattern::GROUP_CODES => $codes]),
            ...$condition,
            // Spaltenlänge wie bei Textfeldern (Anzeige, Index, Typwechsel).
            'length' => NumberPattern::COLUMN_LENGTH,
        ];
    }

    /**
     * Gruppe eines Nummernkreises (N4): ein aktives Pflichtfeld vom Typ Auswahl derselben Tabelle und je Wahl ein
     * Kurzzeichen für {GROUP}. Fehlt eines, gilt der Wert der Wahl, wenn er ein gültiges Kurzzeichen ist. Einträge für
     * Werte, die (noch) keine Wahl sind, bleiben: entfernte Wahlen (alte Nummern zurücklesen) und künftige.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: array<string, string>} Gruppenfeld ('' = ohne) und Wert => Kurzzeichen
     */
    private function numberGroup(array $options, ?CustomTable $owner): array
    {
        $groupField = trim((string) ($options[NumberPattern::GROUP_FIELD] ?? ''));

        if ($groupField === '') {
            return ['', []];
        }

        $select = $owner?->fields()->where('name', $groupField)->first();

        if (! $select instanceof CustomField || $select->type !== FieldType::Select) {
            $this->fail(__('numbering.errors.group_field', ['field' => $groupField]));
        }

        if (! $select->is_required || ! $select->is_active) {
            $this->fail(__('numbering.errors.group_field_required', ['field' => $groupField]));
        }

        $codes = [];

        foreach ((array) ($options[NumberPattern::GROUP_CODES] ?? []) as $value => $code) {
            $code = trim((string) $code);

            if ($code === '') {
                continue;
            }

            if (! NumberPattern::validCode($code)) {
                $this->fail(__('numbering.errors.group_code', ['code' => $code, 'max' => NumberPattern::MAX_CODE]));
            }

            $codes[(string) $value] = $code;
        }

        foreach (self::choiceValues($select) as $value) {
            if (isset($codes[$value])) {
                continue;
            }

            if (! NumberPattern::validCode($value)) {
                $this->fail(__('numbering.errors.group_choice', ['value' => $value, 'max' => NumberPattern::MAX_CODE]));
            }

            $codes[$value] = $value;
        }

        return [$groupField, $codes];
    }

    /**
     * Gespeicherte Werte eines Auswahlfelds.
     *
     * @return list<string>
     */
    public static function choiceValues(CustomField $select): array
    {
        return array_values(array_map(
            static fn (mixed $c): string => (string) (is_array($c) ? ($c['value'] ?? '') : $c),
            (array) ($select->optionsArray()['options'] ?? []),
        ));
    }

    /**
     * Dateifeld: erlaubte Arten, Höchstgrösse je Datei (MB) und Anzahl Dateien (1 = eine Datei).
     *
     * @param  array<string, mixed>  $options
     * @return array{kinds: list<string>, max_mb: int, max_files: int}
     */
    private function fileOptions(array $options): array
    {
        return [
            'kinds' => FileKinds::normalize((array) ($options['kinds'] ?? [])),
            'max_mb' => $this->int($options['max_mb'] ?? config('dynamic_data.files.default_max_mb', 10), 1, (int) config('dynamic_data.files.limit_max_mb', 200), __('tables.fields.file_max_mb')),
            'max_files' => $this->int($options['max_files'] ?? 1, 1, (int) config('dynamic_data.files.max_files', 50), __('tables.fields.file_max_files')),
        ];
    }

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

        // Verknüpfungen innerhalb einer App; über App-Grenzen nur auf freigegebene Tabellen (B-107, Schnittstellen).
        $foreign = $owner !== null && AppInterfaces::foreign($target, $owner->app_id);

        if ($foreign && ! $target->is_shared) {
            $this->fail(__('apps.errors.cross_app_relation', ['table' => (string) $target->label, 'app' => (string) $target->app?->name]));
        }

        if ($displayFields === []) {
            $this->fail(__('tables.errors.unknown_display_field', ['field' => '', 'table' => $tableName]));
        }

        foreach ($displayFields as $displayField) {
            $display = $target->fields()->where('name', $displayField)->first();

            if (! $display instanceof CustomField || $display->type === FieldType::Relation) {
                $this->fail(__('tables.errors.unknown_display_field', ['field' => $displayField, 'table' => $tableName]));
            }

            if ($foreign && ! $display->is_shared) {
                $this->fail(__('apps.errors.not_shared_field', ['field' => $displayField, 'table' => (string) $target->label]));
            }
        }

        $stored = RelationDisplay::store(['table' => $tableName], $displayFields, $options['separator'] ?? null);

        // Kind-Datensätze mitkopieren (B-123): nur innerhalb der App, nie über eine Selbstreferenz (Hierarchie).
        if ((bool) ($options[self::COPY_WITH_PARENT] ?? false)) {
            if ($foreign || $target === $owner) {
                $this->fail(__($foreign ? 'tables.errors.copy_with_parent_foreign' : 'tables.errors.copy_with_parent_self'));
            }

            $stored[self::COPY_WITH_PARENT] = true;
        }

        return $stored;
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
