<?php

declare(strict_types=1);

namespace App\DynamicData\Bulk;

use App\DynamicData\DataAccess;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Änderungen einer Sammeländerung (B-133, BULK_EDIT.md §4): je Feld „setzen“ (ein Wert für alle) oder „leeren“.
 * Nur Feldinhalte, nie Struktur. Nicht änderbar sind Nummernkreise, berechnete Felder, Dateien, inaktive, im Formular
 * ausgeblendete und schreibgeschützte Felder sowie gesperrte (die Verknüpfung zur Haupttabelle in einer Detailtabelle);
 * eindeutige Felder lassen sich nur leeren, Pflichtfelder nur setzen.
 */
final readonly class BulkChange
{
    public const string SET = 'set';

    public const string CLEAR = 'clear';

    /**
     * @param  array<string, array{op: string, value: mixed}>  $changes  Feldname => Art und Wert
     * @param  list<string>  $locked  gesperrte Felder
     */
    public function __construct(
        public CustomTable $table,
        public array $changes,
        public array $locked = [],
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
            $normalized[(string) $field] = ['op' => $op, 'value' => $op === self::CLEAR ? null : ($change['value'] ?? null)];
        }

        return new self($table, $normalized, array_values(array_map('strval', $locked)));
    }

    /** @return array<string, array{op: string, value: mixed}> */
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
                && (self::canSet($field) || self::canClear($field)))
            ->sortBy('sort_order')
            ->keyBy('name')
            ->all();
    }

    public static function canSet(CustomField $field): bool
    {
        return ! $field->is_unique;
    }

    public static function canClear(CustomField $field): bool
    {
        return ! $field->is_required;
    }

    /**
     * Prüft, ob jede Änderung erlaubt ist; der Dialog bietet anderes gar nicht an, hier wird es auf dem Server abgelehnt.
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
            $allowed = match ($change['op']) {
                self::SET => $field !== null && self::canSet($field),
                self::CLEAR => $field !== null && self::canClear($field),
                default => false,
            };

            if (! $allowed) {
                throw new BusinessRuleException(__('data.bulk.errors.not_editable', ['field' => $field->label ?? $name]), 'bulk.not_editable');
            }

            if ($change['op'] === self::SET && $field->is_required && ($change['value'] === null || $change['value'] === '')) {
                throw new BusinessRuleException(__('data.bulk.errors.required', ['field' => $field->label]), 'bulk.required');
            }
        }
    }

    /** @return array<string, mixed> Feldname => neuer Wert (null = leeren) */
    public function values(): array
    {
        return array_map(static fn (array $change): mixed => $change['op'] === self::CLEAR ? null : $change['value'], $this->changes);
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_keys($this->changes);
    }

    /** Werte in den Datensatz übernehmen (noch nicht speichern); true, wenn sich dabei etwas ändert. */
    public function apply(DynamicRecord $record): bool
    {
        $record->fill($this->values());

        return $record->isDirty($this->fieldNames());
    }
}
