<?php

declare(strict_types=1);

namespace App\DynamicData\Ddl;

use Illuminate\Database\Connection;

/**
 * MySQL (ab 8.0.16): eindeutige Werte unter nicht gelöschten Datensätzen über einen funktionalen
 * Index. Alles Übrige siehe MySqlFamilyDialect.
 */
final class MySqlDialect extends MySqlFamilyDialect
{
    /**
     * Keine Teil-Indizes in MySQL: Der funktionale Index enthält den Wert nur für nicht gelöschte
     * Datensätze, sonst NULL – und NULL darf mehrfach vorkommen.
     */
    public function createActiveUniqueIndex(Connection $connection, string $qualifiedTable, string $column, string $indexName): void
    {
        $grammar = $this->grammar($connection);

        $connection->statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s ((CASE WHEN %s IS NULL THEN %s END))',
            $grammar->wrap($indexName),
            $grammar->wrapTable($qualifiedTable),
            $grammar->wrap('deleted_at'),
            $grammar->wrap($column),
        ));
    }
}
