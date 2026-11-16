<?php

declare(strict_types=1);

namespace App\Reports\Dialects;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PostgresQueryDialect implements QueryDialect
{
    public function dateBucket(string $column, string $bucket): Expression
    {
        return DB::raw(match ($bucket) {
            'day' => "to_char({$column}, 'YYYY-MM-DD')",
            'week' => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            'quarter' => "to_char({$column}, 'YYYY-\"Q\"Q')",
            'year' => "to_char({$column}, 'YYYY')",
            default => "to_char({$column}, 'YYYY-MM')",
        });
    }

    public function whereLike(Builder $query, string $column, string $pattern): void
    {
        $query->where($column, 'ilike', $pattern);
    }

    public function withTimeout(Connection $connection, int $seconds, Closure $work): mixed
    {
        // SET LOCAL gilt nur bis zum Ende der Transaktion.
        return $connection->transaction(function () use ($connection, $seconds, $work): mixed {
            $connection->statement('SET LOCAL statement_timeout = '.max(1, $seconds) * 1000);

            return $work();
        });
    }

    public function isTimeout(QueryException $e): bool
    {
        return $e->getCode() === '57014';
    }

    public function decimal(string $sql): string
    {
        return 'CAST('.$sql.' AS numeric)';
    }
}
