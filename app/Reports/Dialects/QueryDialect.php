<?php

declare(strict_types=1);

namespace App\Reports\Dialects;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * Die einzigen datenbankspezifischen Ausdrücke der Report-Engine (wie die DDL-Dialekte des
 * Table-Designers). Alles andere baut der Query Builder.
 */
interface QueryDialect
{
    /**
     * Datum als sortierbarer Text der Stufe: day JJJJ-MM-TT, week JJJJ-MM-TT (Montag),
     * month JJJJ-MM, quarter JJJJ-Qn, year JJJJ.
     *
     * @param  string  $column  bereits maskierter Spaltenausdruck
     */
    public function dateBucket(string $column, string $bucket): Expression;

    /**
     * Wert als Dezimalzahl für Formeln (exakt, auch Ganzzahl-Division wie 7 / 2 = 3,5; ROUND kaufmännisch).
     */
    public function decimal(string $sql): string;

    /** Enthält bzw. beginnt mit, ohne Unterscheidung von Groß- und Kleinschreibung. */
    public function whereLike(Builder $query, string $column, string $pattern): void;

    /**
     * Führt die Abfrage mit einem Zeitlimit aus.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function withTimeout(Connection $connection, int $seconds, Closure $work): mixed;

    /** Abbruch durch das Zeitlimit. */
    public function isTimeout(QueryException $e): bool;
}
