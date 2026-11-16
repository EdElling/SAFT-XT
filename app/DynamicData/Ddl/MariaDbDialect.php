<?php

declare(strict_types=1);

namespace App\DynamicData\Ddl;

use Illuminate\Database\Connection;
use LogicException;

/**
 * MariaDB (ab 10.11): kennt keine funktionalen Indizes. Eindeutige Werte unter nicht gelöschten
 * Datensätzen daher über eine unsichtbare berechnete Hilfsspalte <index>_v (Wert nur, solange
 * deleted_at leer ist, sonst NULL) mit eindeutigem Index. SELECT * liefert sie nicht; sie entsteht
 * und verschwindet mit dem Index. Alles Übrige siehe MySqlFamilyDialect.
 */
final class MariaDbDialect extends MySqlFamilyDialect
{
    private const string HELPER_SUFFIX = '_v';

    public function createActiveUniqueIndex(Connection $connection, string $qualifiedTable, string $column, string $indexName): void
    {
        [$namespace, $table] = explode('.', $qualifiedTable, 2);
        $grammar = $this->grammar($connection);
        $helper = $indexName.self::HELPER_SUFFIX;

        $connection->statement(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s AS (CASE WHEN %s IS NULL THEN %s END) VIRTUAL INVISIBLE, ADD UNIQUE INDEX %s (%s)',
            $grammar->wrapTable($qualifiedTable),
            $grammar->wrap($helper),
            $this->columnType($connection, $namespace, $table, $column),
            $grammar->wrap('deleted_at'),
            $grammar->wrap($column),
            $grammar->wrap($indexName),
            $grammar->wrap($helper),
        ));
    }

    /** Mit dem Index verschwindet auch seine Hilfsspalte (sonst ließe sich das Feld nicht löschen). */
    public function dropIndex(Connection $connection, string $namespace, string $table, string $indexName): void
    {
        parent::dropIndex($connection, $namespace, $table, $indexName);

        $helper = $indexName.self::HELPER_SUFFIX;
        $exists = $connection->table('information_schema.columns')
            ->where('table_schema', $namespace)
            ->where('table_name', $table)
            ->where('column_name', $helper)
            ->exists();

        if ($exists) {
            $grammar = $this->grammar($connection);
            $connection->statement(sprintf('ALTER TABLE %s DROP COLUMN %s', $grammar->wrapTable($this->qualify($namespace, $table)), $grammar->wrap($helper)));
        }
    }

    public function isHelperColumn(string $column): bool
    {
        return preg_match('/^cf_\d+_uq'.self::HELPER_SUFFIX.'$/', $column) === 1;
    }

    /** Typ der Feldspalte für die Hilfsspalte, z. B. varchar(100), bigint(20) unsigned, decimal(12,2). */
    private function columnType(Connection $connection, string $namespace, string $table, string $column): string
    {
        $type = (string) $connection->table('information_schema.columns')
            ->where('table_schema', $namespace)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('column_type');

        if (preg_match('/^[a-z]+(\(\d+(,\d+)?\))?( unsigned)?$/i', $type) !== 1) {
            throw new LogicException("Spaltentyp [{$type}] für eindeutigen Index nicht vorgesehen.");
        }

        return $type;
    }
}
