<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

use App\Models\CustomField;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Ordnet Datenbank-Exceptions treiberunabhängig einer Fehlerart zu
 * (PostgreSQL, MySQL/MariaDB, SQL Server, SQLite).
 *
 * Grundlage sind SQLSTATE (ISO/ANSI) und – wo Treiber nur das generische
 * 23000 liefern – die treiberspezifischen Fehlercodes bzw. Meldungen.
 */
final class DatabaseErrorClassifier
{
    /** @var array<string, DatabaseErrorType> SQLSTATE => Typ (PostgreSQL liefert präzise Codes) */
    private const array SQLSTATE = [
        '23505' => DatabaseErrorType::UniqueViolation,
        '23503' => DatabaseErrorType::ForeignKeyViolation,
        '23502' => DatabaseErrorType::NotNullViolation,
        '23514' => DatabaseErrorType::CheckViolation,
        '22003' => DatabaseErrorType::ValueOutOfRange,
        '40001' => DatabaseErrorType::Concurrency,
        '40P01' => DatabaseErrorType::Concurrency,
    ];

    /** @var array<int, DatabaseErrorType> Treiber-Code => Typ (MySQL/MariaDB, SQL Server) */
    private const array DRIVER_CODES = [
        // MySQL / MariaDB
        1062 => DatabaseErrorType::UniqueViolation,
        1451 => DatabaseErrorType::ForeignKeyViolation,
        1452 => DatabaseErrorType::ForeignKeyViolation,
        1048 => DatabaseErrorType::NotNullViolation,
        1364 => DatabaseErrorType::NotNullViolation,
        3819 => DatabaseErrorType::CheckViolation,
        4025 => DatabaseErrorType::CheckViolation,
        1264 => DatabaseErrorType::ValueOutOfRange,
        1213 => DatabaseErrorType::Concurrency,
        1205 => DatabaseErrorType::Concurrency,
        // SQL Server (547 = FK oder CHECK, siehe classifyByMessage)
        2627 => DatabaseErrorType::UniqueViolation,
        2601 => DatabaseErrorType::UniqueViolation,
        515 => DatabaseErrorType::NotNullViolation,
        8115 => DatabaseErrorType::ValueOutOfRange,
    ];

    /** @var array<string, DatabaseErrorType> Meldungsfragment => Typ (SQLite, SQL Server 547) */
    private const array MESSAGES = [
        'UNIQUE constraint failed' => DatabaseErrorType::UniqueViolation,
        'FOREIGN KEY constraint failed' => DatabaseErrorType::ForeignKeyViolation,
        'NOT NULL constraint failed' => DatabaseErrorType::NotNullViolation,
        'CHECK constraint failed' => DatabaseErrorType::CheckViolation,
        'conflicted with the FOREIGN KEY' => DatabaseErrorType::ForeignKeyViolation,
        'conflicted with the REFERENCE' => DatabaseErrorType::ForeignKeyViolation,
        'conflicted with the CHECK' => DatabaseErrorType::CheckViolation,
    ];

    public function __construct(
        private readonly LostConnectionDetector $lostConnection,
        private readonly ConcurrencyErrorDetector $concurrency,
    ) {}

    /**
     * @return DatabaseErrorType|null null, wenn es kein Datenbankfehler ist.
     */
    public function classify(Throwable $e): ?DatabaseErrorType
    {
        if (! $e instanceof QueryException && ! $e instanceof PDOException) {
            return null;
        }

        if ($e instanceof UniqueConstraintViolationException) {
            return DatabaseErrorType::UniqueViolation;
        }

        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;

        return self::SQLSTATE[$sqlState]
            ?? ($driverCode !== null ? (self::DRIVER_CODES[$driverCode] ?? null) : null)
            ?? $this->classifyByMessage($e->getMessage())
            ?? match (true) {
                $this->lostConnection->causedByLostConnection($e) => DatabaseErrorType::ConnectionLost,
                $this->concurrency->causedByConcurrencyError($e) => DatabaseErrorType::Concurrency,
                default => DatabaseErrorType::Unknown,
            };
    }

    /**
     * Betroffene Spalten einer Unique-Verletzung (soweit vom Treiber geliefert).
     *
     * @return list<string>
     */
    public function uniqueColumns(Throwable $e): array
    {
        if (! $e instanceof UniqueConstraintViolationException) {
            return [];
        }

        if ($e->columns !== [] || $e->index === null) {
            return $e->columns;
        }

        // MySQL nennt nur den Index. Eindeutige Felder des Table-Designers: cf_<Feld-ID>_uq.
        if (preg_match('/^cf_(\d+)_uq$/', $e->index, $m) === 1) {
            $name = CustomField::query()->whereKey((int) $m[1])->value('name');

            return is_string($name) ? [$name] : [];
        }

        return $this->indexColumns($e);
    }

    /**
     * Spalten des verletzten Index. MySQL nennt Tabelle und Index („for key 'tabelle.index'“),
     * MariaDB nur den Index – die Tabelle steht dann in der ausgeführten Anweisung.
     *
     * @return list<string>
     */
    private function indexColumns(UniqueConstraintViolationException $e): array
    {
        if (preg_match("/for key '([^'.]+)\\.([^']+)'/i", $e->getMessage(), $m) === 1) {
            [$table, $indexName] = [$m[1], $m[2]];
        } elseif (preg_match("/for key '([^']+)'/i", $e->getMessage(), $m) === 1
            && preg_match('/^\s*(?:insert\s+(?:ignore\s+)?into|update)\s+((?:`[^`]+`\.)?`[^`]+`|[\w.]+)/i', $e->getSql(), $t) === 1) {
            [$table, $indexName] = [str_replace('`', '', $t[1]), $m[1]];
        } else {
            return [];
        }

        try {
            $index = collect(DB::connection($e->connectionName)->getSchemaBuilder()->getIndexes($table))->firstWhere('name', strtolower($indexName));
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter((array) ($index['columns'] ?? []), 'is_string'));
    }

    /**
     * Name der verletzten CHECK-Regel aus der Meldung (PostgreSQL, SQL Server, MySQL).
     */
    public function checkConstraintName(Throwable $e): ?string
    {
        foreach ([
            '/violates check constraint "([^"]+)"/i',
            '/conflicted with the CHECK constraint "([^"]+)"/i',
            "/Check constraint '([^']+)' is violated/i",
        ] as $pattern) {
            if (preg_match($pattern, $e->getMessage(), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function classifyByMessage(string $message): ?DatabaseErrorType
    {
        foreach (self::MESSAGES as $fragment => $type) {
            if (stripos($message, $fragment) !== false) {
                return $type;
            }
        }

        return null;
    }
}
