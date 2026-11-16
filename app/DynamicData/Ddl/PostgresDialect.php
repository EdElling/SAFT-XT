<?php

declare(strict_types=1);

namespace App\DynamicData\Ddl;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Grammars\Grammar;

/**
 * PostgreSQL: Namensraum = Schema. Einzige Stelle mit Raw-DDL für dieses Modul.
 * Bezeichner werden ausschließlich über die Grammar gequotet und sind vorher durch
 * den IdentifierValidator geprüft.
 */
final class PostgresDialect implements DdlDialect
{
    public function ensureNamespace(Connection $connection, string $namespace): void
    {
        $connection->statement('CREATE SCHEMA IF NOT EXISTS '.$this->wrap($connection, $namespace));
    }

    public function dropNamespace(Connection $connection, string $namespace): void
    {
        $connection->statement('DROP SCHEMA IF EXISTS '.$this->wrap($connection, $namespace).' CASCADE');
    }

    public function qualify(string $namespace, string $table): string
    {
        return $namespace.'.'.$table;
    }

    public function createActiveUniqueIndex(Connection $connection, string $qualifiedTable, string $column, string $indexName): void
    {
        $grammar = $this->grammar($connection);

        $connection->statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s) WHERE %s IS NULL',
            $grammar->wrap($indexName),
            $grammar->wrapTable($qualifiedTable),
            $grammar->wrap($column),
            $grammar->wrap('deleted_at'),
        ));
    }

    /** Indexnamen sind je Schema eindeutig; die Tabelle wird nicht gebraucht. */
    public function dropIndex(Connection $connection, string $namespace, string $table, string $indexName): void
    {
        $connection->statement('DROP INDEX IF EXISTS '.$this->grammar($connection)->wrapTable($namespace.'.'.$indexName));
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    public function maxIndexedStringLength(): ?int
    {
        return null;
    }

    /** PostgreSQL lagert lange Werte aus (TOAST); eine feste Zeilengrenze gibt es praktisch nicht. */
    public function isRowSizeError(QueryException $e): bool
    {
        return false;
    }

    private function wrap(Connection $connection, string $identifier): string
    {
        return $this->grammar($connection)->wrap($identifier);
    }

    /** Die Schema-Grammar wird von Laravel erst mit dem Schema Builder initialisiert. */
    private function grammar(Connection $connection): Grammar
    {
        $connection->getSchemaBuilder();

        return $connection->getSchemaGrammar();
    }

    public function isHelperColumn(string $column): bool
    {
        return false;
    }
}
