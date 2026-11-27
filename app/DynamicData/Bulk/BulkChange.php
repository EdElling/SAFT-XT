<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\DataAccess;
use App\DynamicData\Formula\Evaluator;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Änderungen einer Sammeländerung (B-133, BULK_EDIT.md §4, §12): je Feld eine Art.
 *
 * - „setzen“ (ein Wert für alle) und „leeren“ (S1);
 * - „rechnen“: Zahl + − × ÷ und „um %“ mit Runden, Datum ± Tage oder Monate (BulkCalc);
 * - „ersetzen“: Suchtext → Ersatz in Text (BulkReplace);
 * - „Formel“: Formelsprache je Datensatz, für Zahlen und Ja/Nein (BulkFormula).
 *
 * Die drei neuen Arten rechnen je Datensatz aus dem bisherigen Wert (resolve()); alle sehen die Werte vor der
 * Änderung. Ein leerer bisheriger Wert, ein Text ohne Treffer oder ein leeres Formelergebnis lässt das Feld
 * unverändert („übersprungen“). Nur Feldinhalte, nie Struktur. Nicht änderbar sind Nummernkreise, berechnete Felder,
 * Dateien, inaktive, im Formular ausgeblendete und schreibgeschützte Felder sowie gesperrte (die Verknüpfung zur
 * Haupttabelle in einer Detailtabelle). Eindeutige Felder lassen sich nicht setzen, Pflichtfelder nicht leeren.
 */
final class BulkChange
{
    public const string SET = 'set';

    public const string CLEAR = 'clear';

    public const string CALC = 'calc';

    public const string REPLACE = 'replace';

    public const string FORMULA = 'formula';

    /** @var array<string, array<string, mixed>> übersetzte Formeln je Feld */
    private array $compiled = [];

    /**
     * @param  array<string, array<string, mixed>>  $changes  Feldname => ['op' => Art, 'value' => Wert, …Angaben der Art]
     * @param  list<string>  $locked  gesperrte Felder
     */
    public function __construct(
        public readonly CustomTable $table,
        public readonly array $changes,
        public readonly array $locked = [],
    ) {}

    /**
     * Aus den Parametern einer Arbeit bzw. den Eingaben des Dialogs.
     *
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $locked
     */
    public static function fromArray(CustomTable $table, array $changes, array $locked = []): self
    {
        $normalized = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $op = (string) ($change['op'] ?? self::SET);
            $normalized[(string) $field] = match ($op) {
                self::SET => ['op' => $op, 'value' => $change['value'] ?? null],
                self::CALC => [
                    'op' => $op,
                    'operator' => (string) ($change['operator'] ?? ''),
                    'operand' => is_scalar($change['operand'] ?? null) ? (string) $change['operand'] : '',
                    'round_mode' => in_array($change['round_mode'] ?? null, [BulkCalc::ROUND_DIGITS, BulkCalc::ROUND_MULTIPLE], true) ? $change['round_mode'] : null,
                    'round_value' => is_scalar($change['round_value'] ?? null) && (string) $change['round_value'] !== '' ? (string) $change['round_value'] : null,
                ],
                self::REPLACE => [
                    'op' => $op,
                    'search' => is_scalar($change['search'] ?? null) ? (string) $change['search'] : '',
                    'replace' => is_scalar($change['replace'] ?? null) ? (string) $change['replace'] : '',
                    'case' => (bool) ($change['case'] ?? false),
                    'word' => (bool) ($change['word'] ?? false),
                ],
                self::FORMULA => ['op' => $op, 'formula' => trim(is_scalar($change['formula'] ?? null) ? (string) $change['formula'] : '')],
                default => ['op' => $op, 'value' => null],
            };
        }

        return new self($table, $normalized, array_values(array_map('strval', $locked)));
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->changes;
    }

    /** Darf die Tabelle überhaupt eine Sammeländerung haben (ohne Rechte der Person)? */
    public static function supports(CustomTable $table): bool
    {
        return $table->bulk_enabled && ! $table->isExternal() && $table->hasSingleKey() && $table->supports('update');
    }

    /** Sammeländerung für die angemeldete Person: Tabelle erlaubt sie und Recht „ändern“. */
    public static function allowed(CustomTable $table): bool
    {
        return self::supports($table) && DataAccess::current($table, 'update');
    }

    /**
     * Felder, die der Dialog anbietet, in der Reihenfolge des Formulars.
     *
     * @param  list<string>  $locked
     * @return array<string, CustomField>
     */
    public static function editableFields(CustomTable $table, array $locked = []): array
    {
        return $table->fields
            ->filter(static fn (CustomField $field): bool => $field->is_active
                && $field->show_in_form
                && ! $field->is_read_only
                && ! in_array($field->name, $locked, true)
                && ! in_array($field->type, [FieldType::Computed, FieldType::File, FieldType::Number], true)
                && self::ops($field) !== [])
            ->sortBy('sort_order')
            ->keyBy('name')
            ->all();
    }

    /**
     * Arten, die das Feld erlaubt, in der Reihenfolge des Dialogs.
     *
     * @return list<string>
     */
    public static function ops(CustomField $field): array
    {
        return array_values(array_filter([
            self::canSet($field) ? self::SET : null,
            self::canClear($field) ? self::CLEAR : null,
            self::canCalc($field) ? self::CALC : null,
            self::canReplace($field) ? self::REPLACE : null,
            self::canFormula($field) ? self::FORMULA : null,
        ]));
    }

    public static function canSet(CustomField $field): bool
    {
        return ! $field->is_unique;
    }

    public static function canClear(CustomField $field): bool
    {
        return ! $field->is_required;
    }

    /** Rechnen: Zahlen und Datumsfelder. */
    public static function canCalc(CustomField $field): bool
    {
        return in_array($field->type, [FieldType::Integer, FieldType::Decimal, FieldType::Date, FieldType::DateTime], true);
    }

    /** Ersetzen: nur Text (Auswahl hat den gespeicherten Wert, Verknüpfung einen Schlüssel). */
    public static function canReplace(CustomField $field): bool
    {
        return in_array($field->type, [FieldType::String, FieldType::Text], true);
    }

    /** Formel: die Formelsprache liefert nur Zahlen, also Ganzzahl, Dezimalzahl und Ja/Nein. */
    public static function canFormula(CustomField $field): bool
    {
        return in_array($field->type, [FieldType::Integer, FieldType::Decimal, FieldType::Boolean], true);
    }

    public static function isDate(CustomField $field): bool
    {
        return in_array($field->type, [FieldType::Date, FieldType::DateTime], true);
    }

    /** Nachkommastellen des Felds (Ganzzahl 0). */
    public static function scale(CustomField $field): int
    {
        return $field->type === FieldType::Decimal ? (int) ($field->optionsArray()['scale'] ?? 2) : 0;
    }

    /**
     * Prüft, ob jede Änderung erlaubt und vollständig ist; der Dialog bietet anderes gar nicht an, hier wird es auf dem
     * Server abgelehnt.
     *
     * @throws BusinessRuleException
     */
    public function validate(): void
    {
        if ($this->changes === []) {
            throw new BusinessRuleException(__('data.bulk.errors.no_changes'), 'bulk.no_changes');
        }

        $fields = self::editableFields($this->table, $this->locked);

        foreach ($this->changes as $name => $change) {
            $field = $fields[$name] ?? null;

            if ($field === null || ! in_array($change['op'], self::ops($field), true)) {
                throw new BusinessRuleException(__('data.bulk.errors.not_editable', ['field' => $field->label ?? $name]), 'bulk.not_editable');
            }

            match ($change['op']) {
                self::SET => $field->is_required && ($change['value'] === null || $change['value'] === '')
                    ? throw new BusinessRuleException(__('data.bulk.errors.required', ['field' => $field->label]), 'bulk.required')
                    : null,
                self::CALC => $this->validateCalc($field, $change),
                self::REPLACE => $change['search'] === ''
                    ? throw new BusinessRuleException(__('data.bulk.errors.search_empty', ['field' => $field->label]), 'bulk.search_empty')
                    : null,
                self::FORMULA => $this->formula($field),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $change
     *
     * @throws BusinessRuleException
     */
    private function validateCalc(CustomField $field, array $change): void
    {
        $fail = static fn (string $key, array $replace = []): never => throw new BusinessRuleException(__("data.bulk.errors.{$key}", ['field' => $field->label, ...$replace]), "bulk.{$key}");

        if (self::isDate($field)) {
            if (! in_array($change['operator'], BulkCalc::DATE_OPERATORS, true)) {
                $fail('operator');
            }

            if (! ctype_digit($change['operand']) || (int) $change['operand'] > BulkCalc::MAX_DATE_OPERAND) {
                $fail('days', ['max' => BulkCalc::MAX_DATE_OPERAND]);
            }

            return;
        }

        if (! in_array($change['operator'], BulkCalc::NUMBER_OPERATORS, true)) {
            $fail('operator');
        }

        $operand = BulkCalc::number($change['operand']) ?? $fail('operand');

        if ($change['operator'] === 'div' && bccomp($operand, '0', 12) === 0) {
            $fail('division_by_zero');
        }

        $scale = self::scale($field);

        if ($change['round_mode'] === BulkCalc::ROUND_DIGITS
            && ($change['round_value'] === null || ! ctype_digit($change['round_value']) || (int) $change['round_value'] > $scale)) {
            $fail('round_digits', ['max' => $scale]);
        }

        if ($change['round_mode'] === BulkCalc::ROUND_MULTIPLE
            && (($multiple = BulkCalc::number($change['round_value'])) === null || ! BulkCalc::validMultiple($multiple, $scale))) {
            $fail('round_multiple', ['scale' => $scale]);
        }
    }

    /**
     * Übersetzte Formel des Felds (einmal je Sammeländerung).
     *
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    private function formula(CustomField $field): array
    {
        if (! isset($this->compiled[$field->name])) {
            try {
                $this->compiled[$field->name] = BulkFormula::compile($this->table, (string) $this->changes[$field->name]['formula']);
            } catch (FormulaException $e) {
                throw new BusinessRuleException(__('data.bulk.errors.formula', ['field' => $field->label, 'message' => $e->getMessage()]), 'bulk.formula');
            }
        }

        return $this->compiled[$field->name];
    }

    /**
     * Neue Werte für einen Datensatz, aus seinen bisherigen Werten (vor jeder Änderung dieser Sammeländerung).
     *
     * values: Feld => neuer Wert für die Felder, die sich ändern sollen (null = leeren); skipped: Felder ohne
     * bisherigen Wert, ohne Treffer oder mit leerem Formelergebnis; errors: Feld => Meldung (Division durch 0).
     * Voraussetzung: validate() ist gelaufen.
     *
     * @return array{values: array<string, mixed>, skipped: list<string>, errors: array<string, string>}
     */
    public function resolve(DynamicRecord $record): array
    {
        $values = [];
        $skipped = [];
        $errors = [];
        $fields = $this->table->fields->keyBy('name');

        foreach ($this->changes as $name => $change) {
            /** @var CustomField $field */
            $field = $fields[$name];
            $current = $record->getAttribute($name);

            try {
                $value = match ($change['op']) {
                    self::SET => $change['value'],
                    self::CLEAR => null,
                    self::CALC => self::isDate($field)
                        ? BulkCalc::shift($current, $change['operator'], (int) $change['operand'], $field->type === FieldType::DateTime)
                        : BulkCalc::calculate($current, $change['operator'], (string) BulkCalc::number($change['operand']), self::scale($field), $change['round_mode'], $change['round_mode'] === BulkCalc::ROUND_MULTIPLE ? BulkCalc::number($change['round_value']) : $change['round_value']),
                    self::REPLACE => BulkReplace::apply($current, $change['search'], $change['replace'], $change['case'], $change['word']),
                    self::FORMULA => $this->formulaValue($field, BulkFormula::evaluate($this->formula($field), $record)),
                    default => null,
                };
            } catch (FormulaException $e) {
                $errors[$name] = $e->getMessage();

                continue;
            }

            $skip = match ($change['op']) {
                self::CALC, self::FORMULA => $value === null,
                self::REPLACE => $value === BulkReplace::NO_MATCH,
                default => false,
            };

            if ($skip) {
                $skipped[] = $name;

                continue;
            }

            $values[$name] = $value;
        }

        return ['values' => $values, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** Formelergebnis für das Feld: auf die Nachkommastellen gerundet, Ja/Nein ungleich 0 = ja. */
    private function formulaValue(CustomField $field, ?string $result): mixed
    {
        return match (true) {
            $result === null => null,
            $field->type === FieldType::Boolean => bccomp($result, '0', 12) !== 0,
            default => Evaluator::round($result, self::scale($field)),
        };
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_keys($this->changes);
    }

    /**
     * Werte in den Datensatz übernehmen (noch nicht speichern); true, wenn sich dabei etwas ändert.
     *
     * @param  array<string, mixed>|null  $values  aus resolve(), sonst hier berechnet
     *
     * @throws BusinessRuleException wenn die Rechnung für den Datensatz scheitert
     */
    public function apply(DynamicRecord $record, ?array $values = null): bool
    {
        if ($values === null) {
            $resolved = $this->resolve($record);

            if ($resolved['errors'] !== []) {
                throw new BusinessRuleException(implode(' · ', $this->errorTexts($resolved['errors'])), 'bulk.value');
            }

            $values = $resolved['values'];
        }

        $record->fill($values);

        return $record->isDirty(array_keys($values));
    }

    /**
     * Fehler mit Feldbezeichnung, z. B. „Tarif: Division durch 0.“
     *
     * @param  array<string, string>  $errors
     * @return list<string>
     */
    public function errorTexts(array $errors): array
    {
        $labels = $this->table->fields->keyBy('name');

        return array_values(array_map(
            static fn (string $name, string $message): string => ($labels[$name]->label ?? $name).': '.$message,
            array_keys($errors),
            $errors,
        ));
    }
}
