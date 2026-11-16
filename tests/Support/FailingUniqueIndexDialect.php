<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DynamicData\Ddl\DdlDialect;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Dialekt der eingestellten Datenbank, der beim Anlegen eines eindeutigen Index scheitert –
 * also mitten in einer Schemaänderung, nachdem andere DDL schon ausgeführt ist.
 */
final class FailingUniqueIndexDialect implements DdlDialect
{
    public const string MESSAGE = 'DDL-Fehler simuliert';

    public function __construct(private readonly DdlDialect $inner) {}

    /** Ersetzt den Dialekt des aktuellen Treibers im Container. */
    public static function install(): void
    {
        $class = config('dynamic_data.dialects.'.app('db')->connection(config('dynamic_data.connection'))->getDriverName());
        $inner = new $class;
        app()->bind($class, static fn (): self => new self($inner));
    }

    public function ensureNamespace(Connection $connection, string $namespace): void
    {
        $this->inner->ensureNamespace($connection, $namespace);
    }

    public function dropNamespace(Connection $connection, string $namespace): void
    {
        $this->inner->dropNamespace($connection, $namespace);
    }

    public function qualify(string $namespace, string $table): string
    {
        return $this->inner->qualify($namespace, $table);
    }

    public function createActiveUniqueIndex(Connection $connection, string $qualifiedTable, string $column, string $indexName): void
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function dropIndex(Connection $connection, string $namespace, string $table, string $indexName): void
    {
        $this->inner->dropIndex($connection, $namespace, $table, $indexName);
    }

    public function supportsTransactionalDdl(): bool
    {
        return $this->inner->supportsTransactionalDdl();
    }

    public function maxIndexedStringLength(): ?int
    {
        return $this->inner->maxIndexedStringLength();
    }

    public function isRowSizeError(QueryException $e): bool
    {
        return $this->inner->isRowSizeError($e);
    }

    public function isHelperColumn(string $column): bool
    {
        return $this->inner->isHelperColumn($column);
    }
}
