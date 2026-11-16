<?php

declare(strict_types=1);

namespace App\DynamicData\Ddl;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Grammars\Grammar;
use LogicException;

/**
 * Gemeinsames für MySQL und MariaDB: Namensraum = eigene Datenbank auf demselben Server, keine
 * transaktionale DDL, InnoDB-Grenzen. Einzige Stelle mit Raw-DDL für diese Datenbanken (neben den
 * Unterklassen). Bezeichner werden ausschließlich über die Grammar gequotet und sind vorher durch
 * den IdentifierValidator geprüft.
 */
abstract class MySqlFamilyDialect implements DdlDialect
{
    /** InnoDB: höchstens 3072 Byte je Indexschlüssel, utf8mb4 braucht bis zu 4 Byte je Zeichen. */
    private const int MAX_INDEXED_STRING_LENGTH = 768;

    /** ER_TOO_BIG_ROWSIZE: Zeile größer als 65 535 Byte (bzw. die Seitengrenze von InnoDB). */
    private const array ROW_SIZE_ERRORS = [1118];

    public function ensureNamespace(Connection $connection, string $namespace): void
    {
        // Gibt es die Datenbank schon (z. B. beim Hoster angelegt), nicht anlegen: Hoster wie Strato erlauben dem
        // Benutzer kein CREATE DATABASE, auch nicht mit IF NOT EXISTS.
        if ($connection->table('information_schema.schemata')->where('schema_name', $namespace)->exists()) {
            return;
        }

        $connection->statement(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
            $this->wrap($connection, $namespace),
            $this->charsetName($connection->getConfig('charset') ?? 'utf8mb4'),
            $this->charsetName($connection->getConfig('collation') ?? 'utf8mb4_unicode_ci'),
        ));
    }

    public function dropNamespace(Connection $connection, string $namespace): void
    {
        $connection->statement('DROP DATABASE IF EXISTS '.$this->wrap($connection, $namespace));
    }

    public function qualify(string $namespace, string $table): string
    {
        return $namespace.'.'.$table;
    }

    /** Indexnamen gelten je Tabelle; DROP INDEX kennt kein IF EXISTS. */
    public function dropIndex(Connection $connection, string $namespace, string $table, string $indexName): void
    {
        if ($this->indexExists($connection, $namespace, $table, $indexName)) {
            $grammar = $this->grammar($connection);
            $connection->statement(sprintf('DROP INDEX %s ON %s', $grammar->wrap($indexName), $grammar->wrapTable($this->qualify($namespace, $table))));
        }
    }

    /** Bei jeder DDL-Anweisung wird die laufende Transaktion abgeschlossen. */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    public function maxIndexedStringLength(): ?int
    {
        return self::MAX_INDEXED_STRING_LENGTH;
    }

    public function isRowSizeError(QueryException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), self::ROW_SIZE_ERRORS, true);
    }

    public function isHelperColumn(string $column): bool
    {
        return false;
    }

    protected function indexExists(Connection $connection, string $namespace, string $table, string $indexName): bool
    {
        return $connection->table('information_schema.statistics')
            ->where('table_schema', $namespace)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    protected function wrap(Connection $connection, string $identifier): string
    {
        return $this->grammar($connection)->wrap($identifier);
    }

    /** Zeichensatz und Sortierung kommen aus der Konfiguration, nicht von Benutzern. */
    private function charsetName(string $name): string
    {
        if (preg_match('/^[a-z0-9_]+$/i', $name) !== 1) {
            throw new LogicException("Ungültiger Zeichensatz [{$name}].");
        }

        return $name;
    }

    /** Die Schema-Grammar wird von Laravel erst mit dem Schema Builder initialisiert. */
    protected function grammar(Connection $connection): Grammar
    {
        $connection->getSchemaBuilder();

        return $connection->getSchemaGrammar();
    }
}
