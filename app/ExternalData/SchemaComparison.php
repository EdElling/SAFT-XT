<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Vergleicht die gespeicherten Einstellungen einer angebundenen Tabelle mit dem aktuellen Schema.
 * Liest nur; übernommen wird mit TableConnectorService::refresh().
 */
final class SchemaComparison
{
    public function __construct(private readonly SchemaInspector $inspector) {}

    public function compare(CustomTable $table): SchemaDifference
    {
        $connection = $table->databaseConnection ?? throw ExternalTableUnavailable::for((string) $table->label);
        $info = $this->inspector->table($connection, (string) $table->external_schema, (string) $table->external_table);

        if ($info === null) {
            return new SchemaDifference(tableMissing: true);
        }

        $columns = [];

        foreach ($this->inspector->columns($connection, (string) $table->external_schema, (string) $table->external_table) as $column) {
            if ($column->isSupported()) {
                $columns[$column->name] = $column;
            }
        }

        $fields = $table->fields()->get()->keyBy('name');
        $added = array_values(array_filter($columns, static fn (ColumnInfo $c): bool => ! $fields->has($c->name)));
        $missing = [];
        $returned = [];
        $changed = [];

        foreach ($fields as $name => $field) {
            /** @var CustomField $field */
            $options = $field->optionsArray();
            $column = $columns[$name] ?? null;

            if ($column === null) {
                if (! ($options[TableConnectorService::MISSING] ?? false)) {
                    $missing[] = $field;
                }

                continue;
            }

            if ($options[TableConnectorService::MISSING] ?? false) {
                $returned[] = $field;
            }

            $changes = $this->changes($field, $column);

            if ($changes !== []) {
                $changed[] = ['field' => $field, 'column' => $column, 'changes' => $changes];
            }
        }

        $unique = $info->isView() ? [] : $this->inspector->uniqueKeys($connection, (string) $table->external_schema, (string) $table->external_table);
        $keyColumns = $this->keyChange($table, $info, $columns, $unique);
        $writing = $table->allow_create || $table->allow_update || $table->allow_delete;

        return new SchemaDifference(
            added: $added,
            missing: $missing,
            returned: $returned,
            changed: $changed,
            keyColumns: $keyColumns,
            // Schreiben ist freigeschaltet, aber die Datenbank garantiert den Schlüssel nicht mehr.
            keyNotUnique: $keyColumns === null && $writing && ! self::isUnique($table->keyColumns(), $unique),
            checks: $this->checkChanges($table, $this->inspector->checkConstraints($connection, (string) $table->external_schema, (string) $table->external_table)),
        );
    }

    /**
     * Neue, geänderte und entfallene CHECK-Regeln gegenüber dem zuletzt Eingelesenen.
     *
     * @param  list<CheckConstraintInfo>  $current
     * @return list<array{name: string, state: string}>
     */
    private function checkChanges(CustomTable $table, array $current): array
    {
        $stored = collect((array) ($table->check_constraints ?? []))->keyBy('name')->map(static fn (mixed $c): string => (string) ($c['clause'] ?? ''));
        $now = collect($current)->mapWithKeys(static fn (CheckConstraintInfo $c): array => [$c->name => $c->clause]);
        $changes = [];

        foreach ($now as $name => $clause) {
            if (! $stored->has($name)) {
                $changes[] = ['name' => (string) $name, 'state' => 'added'];
            } elseif ($stored[$name] !== $clause) {
                $changes[] = ['name' => (string) $name, 'state' => 'changed'];
            }
        }

        foreach ($stored->keys()->diff($now->keys()) as $name) {
            $changes[] = ['name' => (string) $name, 'state' => 'removed'];
        }

        return $changes;
    }

    /**
     * @return list<string> Schlüssel der Änderungen (type, required, default, unique, generated)
     */
    private function changes(CustomField $field, ColumnInfo $column): array
    {
        $options = $field->optionsArray();
        $baseType = TableConnectorService::baseType($field);
        $changes = [];

        $sizeKeys = ['length', 'precision', 'scale'];
        $storedSize = array_intersect_key($options, array_flip($sizeKeys));
        $newSize = array_intersect_key($column->options, array_flip($sizeKeys));

        if ($baseType !== $column->type || $storedSize != $newSize || $field->external_type !== mb_substr($column->externalType, 0, 128)
            || (bool) ($options['text_only'] ?? false) !== $column->textOnly) {
            $changes[] = 'type';
        }

        if ((bool) ($options['db_required'] ?? false) !== $column->isRequired()) {
            $changes[] = 'required';
        }

        if ((bool) ($options['db_default'] ?? false) !== ($column->default !== null)) {
            $changes[] = 'default';
        }

        if ($field->is_unique !== $column->unique) {
            $changes[] = 'unique';
        }

        if ($field->is_generated !== $column->generated) {
            $changes[] = 'generated';
        }

        return $changes;
    }

    /**
     * Neuer Schlüssel, wenn sich der Primärschlüssel geändert hat. Ein gespeicherter Schlüssel bleibt,
     * solange es seine Spalten gibt und die Datenbank keinen Primärschlüssel hat oder ihn als eindeutig
     * garantiert (z. B. von Hand gewählt bei Views oder über einen eindeutigen Index).
     *
     * @param  array<string, ColumnInfo>  $columns
     * @param  list<list<string>>  $unique
     * @return list<string>|null
     */
    private function keyChange(CustomTable $table, TableInfo $info, array $columns, array $unique): ?array
    {
        $stored = $table->keyColumns();
        $wanted = array_diff($info->keyColumns, array_keys($columns)) === [] ? $info->keyColumns : [];

        if ($wanted === $stored) {
            return null;
        }

        if ($stored !== [] && array_diff($stored, array_keys($columns)) === [] && ($info->keyColumns === [] || self::isUnique($stored, $unique))) {
            return null;
        }

        return $wanted;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<list<string>>  $unique
     */
    private static function isUnique(array $keys, array $unique): bool
    {
        sort($keys);

        return in_array($keys, $unique, true);
    }
}
