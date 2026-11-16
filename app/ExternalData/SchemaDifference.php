<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\Models\CustomField;

/**
 * Abweichungen einer angebundenen Tabelle vom aktuellen Schema der externen Datenbank.
 */
final readonly class SchemaDifference
{
    /**
     * @param  list<ColumnInfo>  $added  neue, unterstützte Spalten
     * @param  list<CustomField>  $missing  aktive Felder ohne (unterstützte) Spalte
     * @param  list<CustomField>  $returned  als „fehlt“ markierte Felder, deren Spalte wieder da ist
     * @param  list<array{field: CustomField, column: ColumnInfo, changes: list<string>}>  $changed
     * @param  list<string>|null  $keyColumns  neuer Primärschlüssel, null = unverändert
     * @param  list<array{name: string, state: string}>  $checks  CHECK-Regeln: added, removed, changed
     */
    public function __construct(
        public bool $tableMissing = false,
        public array $added = [],
        public array $missing = [],
        public array $returned = [],
        public array $changed = [],
        public ?array $keyColumns = null,
        public bool $keyNotUnique = false,
        public array $checks = [],
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->tableMissing && $this->added === [] && $this->missing === [] && $this->returned === [] && $this->changed === [] && $this->keyColumns === null && ! $this->keyNotUnique && $this->checks === [];
    }

    public function count(): int
    {
        return ($this->tableMissing ? 1 : 0) + count($this->added) + count($this->missing) + count($this->returned) + count($this->changed) + ($this->keyColumns === null ? 0 : 1) + ($this->keyNotUnique ? 1 : 0) + count($this->checks);
    }

    /**
     * Lesbare Zeilen, z. B. für den Hinweis in der Bearbeitung und den Befehl.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->tableMissing) {
            return [__('table_connector.schema.table_missing')];
        }

        $lines = [];

        foreach ($this->added as $column) {
            $lines[] = __('table_connector.schema.added', ['column' => $column->name, 'type' => $column->externalType]);
        }

        foreach ($this->missing as $field) {
            $lines[] = __('table_connector.schema.missing', ['column' => $field->name]);
        }

        foreach ($this->returned as $field) {
            $lines[] = __('table_connector.schema.returned', ['column' => $field->name]);
        }

        foreach ($this->changed as $change) {
            $lines[] = __('table_connector.schema.changed', [
                'column' => $change['field']->name,
                'changes' => implode(', ', array_map(static fn (string $c): string => __('table_connector.schema.change.'.$c), $change['changes'])),
            ]);
        }

        if ($this->keyColumns !== null) {
            $lines[] = __('table_connector.schema.key', ['columns' => $this->keyColumns === [] ? '—' : implode(', ', $this->keyColumns)]);
        }

        foreach ($this->checks as $check) {
            $lines[] = __('table_connector.schema.check_'.$check['state'], ['name' => $check['name']]);
        }

        if ($this->keyNotUnique) {
            $lines[] = __('table_connector.schema.key_not_unique');
        }

        return $lines;
    }
}
