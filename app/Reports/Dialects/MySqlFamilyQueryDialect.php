<?php

declare(strict_types=1);

namespace App\Reports\Dialects;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** MySQL und MariaDB: gleiche Datumsfunktionen, unterschiedliches Zeitlimit. */
final class MySqlFamilyQueryDialect implements QueryDialect
{
    public function dateBucket(string $column, string $bucket): Expression
    {
        return DB::raw(match ($bucket) {
            'day' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            'quarter' => "CONCAT(YEAR({$column}), '-Q', QUARTER({$column}))",
            'year' => "DATE_FORMAT({$column}, '%Y')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        });
    }

    public function whereLike(Builder $query, string $column, string $pattern): void
    {
        // Die Standard-Kollation (utf8mb4_*_ci) unterscheidet Groß- und Kleinschreibung nicht.
        $query->where($column, 'like', $pattern);
    }

    public function withTimeout(Connection $connection, int $seconds, Closure $work): mixed
    {
        $mariaDb = $connection->getDriverName() === 'mariadb';
        $variable = $mariaDb ? 'max_statement_time' : 'max_execution_time';
        $previous = $connection->selectOne("SELECT @@SESSION.{$variable} AS v")->v ?? 0;
        $connection->statement("SET SESSION {$variable} = ".($mariaDb ? max(1, $seconds) : max(1, $seconds) * 1000));

        try {
            return $work();
        } finally {
            $connection->statement("SET SESSION {$variable} = ".(is_numeric($previous) ? $previous : 0));
        }
    }

    public function isTimeout(QueryException $e): bool
    {
        // MySQL 3024 (max_execution_time), MariaDB 1969 (max_statement_time).
        return in_array((int) ($e->errorInfo[1] ?? 0), [3024, 1969], true);
    }

    public function decimal(string $sql): string
    {
        return 'CAST('.$sql.' AS DECIMAL(65, 10))';
    }
}
