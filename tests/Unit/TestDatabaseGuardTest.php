<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\TestDatabaseGuard;

/** Schutz der Feature-Tests vor fremden Datenbanken (gecachte Konfiguration, falscher Name). */
class TestDatabaseGuardTest extends TestCase
{
    public function test_test_databases_pass(): void
    {
        TestDatabaseGuard::assertSafe(false, ['Standardverbindung' => 'SAFT2_test', 'app_data' => 'SAFT2_test_app_data', 'EXT_DB_DATABASE' => 'SAFT2_ext_test']);
        $this->addToAssertionCount(1);
    }

    public function test_cached_configuration_aborts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('optimize:clear');
        TestDatabaseGuard::assertSafe(true, ['Standardverbindung' => 'SAFT2_test']);
    }

    public function test_other_database_aborts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('„SAFT2_app_data“');
        TestDatabaseGuard::assertSafe(false, ['Standardverbindung' => 'SAFT2_test', 'app_data' => 'SAFT2_app_data']);
    }

    public function test_production_like_names_abort(): void
    {
        foreach (['SAFT2', 'SAFT2_dusk', 'test_SAFT2', 'SAFT2_testing'] as $name) {
            try {
                TestDatabaseGuard::assertSafe(false, ['Standardverbindung' => $name]);
                $this->fail("{$name} wurde nicht abgelehnt.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
