<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\Database\DatabaseErrorClassifier;
use App\Exceptions\Database\DatabaseErrorType;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseErrorClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?int, string, DatabaseErrorType}>
     */
    public static function driverErrors(): array
    {
        return [
            'pgsql unique' => ['pgsql', '23505', 7, 'duplicate key value violates unique constraint', DatabaseErrorType::UniqueViolation],
            'pgsql foreign key' => ['pgsql', '23503', 7, 'violates foreign key constraint', DatabaseErrorType::ForeignKeyViolation],
            'pgsql not null' => ['pgsql', '23502', 7, 'null value in column', DatabaseErrorType::NotNullViolation],
            'pgsql check' => ['pgsql', '23514', 7, 'violates check constraint', DatabaseErrorType::CheckViolation],
            'pgsql deadlock' => ['pgsql', '40P01', 7, 'deadlock detected', DatabaseErrorType::Concurrency],
            'mysql unique' => ['mysql', '23000', 1062, 'Integrity constraint violation: 1062 Duplicate entry', DatabaseErrorType::UniqueViolation],
            'mysql fk parent' => ['mysql', '23000', 1451, 'Cannot delete or update a parent row', DatabaseErrorType::ForeignKeyViolation],
            'mysql fk child' => ['mysql', '23000', 1452, 'Cannot add or update a child row', DatabaseErrorType::ForeignKeyViolation],
            'mysql not null' => ['mysql', '23000', 1048, "Column 'name' cannot be null", DatabaseErrorType::NotNullViolation],
            'mysql deadlock' => ['mysql', '40001', 1213, 'Deadlock found when trying to get lock', DatabaseErrorType::Concurrency],
            'sqlsrv unique' => ['sqlsrv', '23000', 2627, 'Violation of UNIQUE KEY constraint', DatabaseErrorType::UniqueViolation],
            'sqlsrv fk' => ['sqlsrv', '23000', 547, 'The DELETE statement conflicted with the REFERENCE constraint', DatabaseErrorType::ForeignKeyViolation],
            'sqlsrv check' => ['sqlsrv', '23000', 547, 'The INSERT statement conflicted with the CHECK constraint', DatabaseErrorType::CheckViolation],
            'sqlsrv not null' => ['sqlsrv', '23000', 515, 'Cannot insert the value NULL', DatabaseErrorType::NotNullViolation],
            'sqlite fk' => ['sqlite', '23000', 19, 'FOREIGN KEY constraint failed', DatabaseErrorType::ForeignKeyViolation],
            'sqlite not null' => ['sqlite', '23000', 19, 'NOT NULL constraint failed: users.name', DatabaseErrorType::NotNullViolation],
            'lost connection' => ['mysql', 'HY000', 2006, 'MySQL server has gone away', DatabaseErrorType::ConnectionLost],
            'unknown' => ['pgsql', '42P01', 7, 'relation "foo" does not exist', DatabaseErrorType::Unknown],
        ];
    }

    #[DataProvider('driverErrors')]
    public function test_classifies_driver_errors(string $connection, string $sqlState, ?int $driverCode, string $message, DatabaseErrorType $expected): void
    {
        $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$message}");
        $pdo->errorInfo = [$sqlState, $driverCode, $message];

        $exception = new QueryException($connection, 'select 1', [], $pdo);

        $this->assertSame($expected, $this->classifier()->classify($exception));
    }

    public function test_ignores_non_database_exceptions(): void
    {
        $this->assertNull($this->classifier()->classify(new RuntimeException('boom')));
    }

    public function test_status_codes(): void
    {
        $this->assertSame(409, DatabaseErrorType::ForeignKeyViolation->status());
        $this->assertSame(503, DatabaseErrorType::ConnectionLost->status());
        $this->assertTrue(DatabaseErrorType::UniqueViolation->isExpected());
        $this->assertFalse(DatabaseErrorType::Unknown->isExpected());
    }

    private function classifier(): DatabaseErrorClassifier
    {
        return new DatabaseErrorClassifier(new LostConnectionDetector, new ConcurrencyErrorDetector);
    }
}
