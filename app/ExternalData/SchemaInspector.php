<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\DynamicData\Schema\IdentifierValidator;
use App\Models\DatabaseConnection;
use Illuminate\Database\Connection;

/**
 * Liest Tabellen, Views und Spalten einer externen Datenbank über den Laravel Schema-Builder.
 * Ändert nie etwas an der externen Datenbank.
 */
final class SchemaInspector
{
    /** Spaltennamen, die SAFT ohne Umbenennung verwenden kann. */
    public const string COLUMN_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    public function __construct(
        private readonly ConnectionRegistry $registry,
        private readonly ExternalTypeMapper $types,
        private readonly IdentifierValidator $identifiers,
    ) {}

    /**
     * Tabellen und Views eines Schemas, alphabetisch.
     *
     * @return list<TableInfo>
     */
    public function tables(DatabaseConnection $connection, string $schema): array
    {
        $builder = $this->db($connection)->getSchemaBuilder();
        $tables = [];

        foreach ($builder->getTables($schema) as $table) {
            $tables[] = new TableInfo($schema, (string) $table['name'], TableInfo::KIND_TABLE, filled($table['comment'] ?? null) ? (string) $table['comment'] : null, $this->primaryKey($connection, $schema, (string) $table['name']));
        }

        foreach ($builder->getViews($schema) as $view) {
            $tables[] = new TableInfo($schema, (string) $view['name'], TableInfo::KIND_VIEW);
        }

        usort($tables, static fn (TableInfo $a, TableInfo $b): int => strcmp($a->name, $b->name));

        return $tables;
    }

    public function table(DatabaseConnection $connection, string $schema, string $name): ?TableInfo
    {
        foreach ($this->tables($connection, $schema) as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return list<ColumnInfo>
     */
    public function columns(DatabaseConnection $connection, string $schema, string $table): array
    {
        $db = $this->db($connection);
        $builder = $db->getSchemaBuilder();
        $qualified = $schema.'.'.$table;
        $indexes = $builder->getIndexes($qualified);
        $primary = $this->primaryKey($connection, $schema, $table);
        $unique = [];

        foreach ($indexes as $index) {
            if ($index['unique'] && count($index['columns']) === 1) {
                $unique[] = $index['columns'][0];
            }
        }

        $identity = $this->identityColumns($db, $connection, $schema, $table);
        $json = $connection->driver === ConnectionDriver::Mariadb ? $this->mariaDbJsonColumns($db, $schema, $table) : [];
        $columns = [];

        foreach ($builder->getColumns($qualified) as $column) {
            $name = (string) $column['name'];
            $isJson = in_array($name, $json, true);
            $mapping = $isJson
                ? $this->types->map(ConnectionDriver::Mysql, 'json', 'json')
                : $this->types->map($connection->driver, (string) $column['type_name'], (string) $column['type']);
            $unsupported = match (true) {
                preg_match(self::COLUMN_NAME_PATTERN, $name) !== 1, $this->identifiers->isModelReserved($name) => ColumnInfo::UNSUPPORTED_NAME,
                $mapping['type'] === null => ColumnInfo::UNSUPPORTED_TYPE,
                default => null,
            };

            $columns[] = new ColumnInfo(
                name: $name,
                externalType: $isJson ? 'json' : (string) $column['type'],
                type: $unsupported === null ? $mapping['type'] : null,
                options: $mapping['options'],
                textOnly: $mapping['textOnly'],
                nullable: (bool) $column['nullable'],
                default: $column['default'] === null ? null : (string) $column['default'],
                primary: in_array($name, $primary, true),
                unique: in_array($name, $unique, true),
                generated: (bool) $column['auto_increment'] || $column['generation'] !== null || in_array($name, $identity, true),
                comment: filled($column['comment'] ?? null) ? (string) $column['comment'] : null,
                unsupported: $unsupported,
            );
        }

        return $columns;
    }

    /**
     * Einspaltige Fremdschlüssel; zusammengesetzte sind für Verknüpfungen in SAFT nicht nutzbar.
     *
     * @return list<ForeignKeyInfo>
     */
    public function foreignKeys(DatabaseConnection $connection, string $schema, string $table): array
    {
        $keys = [];

        foreach ($this->db($connection)->getSchemaBuilder()->getForeignKeys($schema.'.'.$table) as $key) {
            if (count($key['columns']) === 1 && count($key['foreign_columns']) === 1) {
                $keys[] = new ForeignKeyInfo(
                    (string) $key['columns'][0],
                    (string) ($key['foreign_schema'] ?? $schema),
                    (string) $key['foreign_table'],
                    (string) $key['foreign_columns'][0],
                );
            }
        }

        return $keys;
    }

    /**
     * Spaltenkombinationen, deren Eindeutigkeit die Datenbank garantiert (Primärschlüssel und
     * eindeutige Indizes), jeweils sortiert. Views haben keine.
     *
     * @return list<list<string>>
     */
    public function uniqueKeys(DatabaseConnection $connection, string $schema, string $table): array
    {
        $keys = [];

        foreach ($this->db($connection)->getSchemaBuilder()->getIndexes($schema.'.'.$table) as $index) {
            if ($index['primary'] || $index['unique']) {
                $columns = array_values(array_map('strval', $index['columns']));
                sort($columns);
                $keys[] = $columns;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function primaryKey(DatabaseConnection $connection, string $schema, string $table): array
    {
        foreach ($this->db($connection)->getSchemaBuilder()->getIndexes($schema.'.'.$table) as $index) {
            if ($index['primary']) {
                return array_values($index['columns']);
            }
        }

        return [];
    }

    /**
     * Identity-Spalten (GENERATED … AS IDENTITY) meldet der Schema-Builder nicht als
     * automatisch gefüllt; information_schema ist SQL-Standard.
     *
     * @return list<string>
     */
    private function identityColumns(Connection $db, DatabaseConnection $connection, string $schema, string $table): array
    {
        if ($connection->driver !== ConnectionDriver::Pgsql) {
            return [];
        }

        return $db->table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('is_identity', 'YES')
            ->pluck('column_name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
    }

    /**
     * CHECK-Regeln einer Tabelle über die Standard-Sicht information_schema (PostgreSQL, MySQL ab
     * 8.0.16, SQL Server). NOT-NULL-Prüfungen, die PostgreSQL dort ebenfalls führt, bleiben außen vor.
     *
     * @return list<CheckConstraintInfo>
     */
    public function checkConstraints(DatabaseConnection $connection, string $schema, string $table): array
    {
        return match ($connection->driver) {
            ConnectionDriver::Pgsql => $this->postgresChecks($connection, $schema, $table),
            ConnectionDriver::Mysql, ConnectionDriver::Mariadb => $this->mysqlChecks($connection, $schema, $table),
            ConnectionDriver::Sqlsrv => $this->sqlServerChecks($connection, $schema, $table),
        };
    }

    /**
     * SQL Server führt information_schema wie PostgreSQL (mit constraint_column_usage), liefert die
     * Spaltennamen der Ergebnisse aber groß geschrieben: daher überall Aliasse.
     *
     * @return list<CheckConstraintInfo>
     */
    private function sqlServerChecks(DatabaseConnection $connection, string $schema, string $table): array
    {
        $db = $this->db($connection);
        $rows = $db->table('information_schema.table_constraints as tc')
            ->join('information_schema.check_constraints as cc', function ($join): void {
                $join->on('cc.constraint_schema', '=', 'tc.constraint_schema')->on('cc.constraint_name', '=', 'tc.constraint_name');
            })
            ->where('tc.table_schema', $schema)
            ->where('tc.table_name', $table)
            ->where('tc.constraint_type', 'CHECK')
            ->orderBy('tc.constraint_name')
            ->get(['tc.constraint_name as constraint_name', 'cc.check_clause as check_clause']);

        $usage = $db->table('information_schema.constraint_column_usage')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->get(['constraint_name as constraint_name', 'column_name as column_name'])
            ->groupBy('constraint_name');

        $checks = [];

        foreach ($rows as $row) {
            $name = (string) $row->constraint_name;
            $columns = ($usage[$name] ?? collect())->pluck('column_name')->map(static fn (mixed $c): string => (string) $c)->unique()->sort()->values()->all();
            $checks[] = new CheckConstraintInfo($name, (string) $row->check_clause, $columns);
        }

        return $checks;
    }

    /**
     * MySQL (ab 8.0.16) kennt keine constraint_column_usage: Die Spalten einer Regel sind die in
     * Backticks genannten Spalten der Tabelle. Werte eines enum kommen als zusätzliche Regel
     * „<spalte> (enum)“ hinzu, damit sie wie eine Werteliste aus CHECK übernommen werden.
     *
     * @return list<CheckConstraintInfo>
     */
    private function mysqlChecks(DatabaseConnection $connection, string $schema, string $table): array
    {
        $db = $this->db($connection);
        $columns = $db->getSchemaBuilder()->getColumns($schema.'.'.$table);
        $names = array_column($columns, 'name');
        $checks = [];

        $rows = $db->table('information_schema.table_constraints as tc')
            ->join('information_schema.check_constraints as cc', function ($join): void {
                $join->on('cc.constraint_schema', '=', 'tc.constraint_schema')->on('cc.constraint_name', '=', 'tc.constraint_name');
            })
            ->where('tc.table_schema', $schema)
            ->where('tc.table_name', $table)
            ->where('tc.constraint_type', 'CHECK')
            ->orderBy('tc.constraint_name')
            ->get(['tc.constraint_name as constraint_name', 'cc.check_clause as check_clause']);

        foreach ($rows as $row) {
            $clause = (string) $row->check_clause;

            // MariaDB: JSON ist longtext mit dieser Regel; die Spalte gilt als JSON, die Regel entfällt.
            if (self::jsonValidColumn($clause) !== null) {
                continue;
            }

            preg_match_all('/`([^`]+)`/', $clause, $used);
            $checkColumns = array_values(array_unique(array_intersect($used[1], $names)));
            sort($checkColumns);
            $checks[] = new CheckConstraintInfo((string) $row->constraint_name, $clause, $checkColumns);
        }

        foreach ($columns as $column) {
            if (strtolower((string) $column['type_name']) !== 'enum') {
                continue;
            }

            $values = array_map(static fn (string $v): string => "'".str_replace("'", "''", $v)."'", ExternalTypeMapper::enumValues((string) $column['type']));
            $checks[] = new CheckConstraintInfo($column['name'].' (enum)', '`'.$column['name'].'` in ('.implode(',', $values).')', [(string) $column['name']]);
        }

        return $checks;
    }

    /**
     * MariaDB speichert JSON als longtext mit CHECK json_valid(`spalte`).
     *
     * @return list<string>
     */
    private function mariaDbJsonColumns(Connection $db, string $schema, string $table): array
    {
        return $db->table('information_schema.check_constraints')
            ->where('constraint_schema', $schema)
            ->where('table_name', $table)
            ->pluck('check_clause')
            ->map(static fn (mixed $clause): ?string => self::jsonValidColumn((string) $clause))
            ->filter()
            ->values()
            ->all();
    }

    private static function jsonValidColumn(string $clause): ?string
    {
        return preg_match('/^\(?json_valid\(`([^`]+)`\)\)?$/i', trim($clause), $m) === 1 ? $m[1] : null;
    }

    /**
     * @return list<CheckConstraintInfo>
     */
    private function postgresChecks(DatabaseConnection $connection, string $schema, string $table): array
    {
        $db = $this->db($connection);
        $rows = $db->table('information_schema.table_constraints as tc')
            ->join('information_schema.check_constraints as cc', function ($join): void {
                $join->on('cc.constraint_schema', '=', 'tc.constraint_schema')->on('cc.constraint_name', '=', 'tc.constraint_name');
            })
            ->where('tc.table_schema', $schema)
            ->where('tc.table_name', $table)
            ->where('tc.constraint_type', 'CHECK')
            ->orderBy('tc.constraint_name')
            ->get(['tc.constraint_name', 'cc.check_clause']);

        $usage = $db->table('information_schema.constraint_column_usage')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->get(['constraint_name', 'column_name'])
            ->groupBy('constraint_name');

        $checks = [];

        foreach ($rows as $row) {
            $clause = (string) $row->check_clause;

            if (preg_match('/^\(*"?[A-Za-z_][A-Za-z0-9_]*"? IS NOT NULL\)*$/i', trim($clause)) === 1) {
                continue;
            }

            $name = (string) $row->constraint_name;
            $columns = ($usage[$name] ?? collect())->pluck('column_name')->map(static fn (mixed $c): string => (string) $c)->unique()->sort()->values()->all();
            $checks[] = new CheckConstraintInfo($name, $clause, $columns);
        }

        return $checks;
    }

    private function db(DatabaseConnection $connection): Connection
    {
        return $this->registry->connection($connection);
    }
}
