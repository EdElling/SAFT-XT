<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DatabaseBackupCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/saft-backup-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /** @param  array<int, string>|string  $command */
    private function targetFile(array|string $command): string
    {
        foreach ((array) $command as $argument) {
            if (str_starts_with($argument, '--file=')) {
                return substr($argument, strlen('--file='));
            }
        }

        $this->fail('Kein --file-Argument');
    }

    public function test_creates_private_dump_without_exposing_the_password(): void
    {
        config(['database.default' => 'pgsql', 'database.connections.pgsql.password' => 'streng-geheim']);

        Process::fake(function (PendingProcess $process) {
            File::put($this->targetFile($process->command), 'dump');

            return Process::result();
        });

        $this->artisan('db:backup', ['--path' => $this->directory])
            ->expectsOutputToContain('Sicherung erstellt')
            ->assertSuccessful();

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command[0] === 'pg_dump'
                && in_array('--format=custom', $process->command, true)
                && end($process->command) === config('database.connections.pgsql.database')
                && ! str_contains(implode(' ', $process->command), 'streng-geheim')
                && $process->environment['PGPASSWORD'] === 'streng-geheim';
        });

        $files = File::files($this->directory);
        $this->assertCount(1, $files);
        $this->assertSame('600', substr(sprintf('%o', $files[0]->getPerms()), -3));
    }

    public function test_failed_dump_reports_error_and_leaves_no_partial_file(): void
    {
        config(['database.default' => 'pgsql']);
        Process::fake(function (PendingProcess $process) {
            File::put($this->targetFile($process->command), 'teilweise');

            return Process::result(errorOutput: 'pg_dump: Verbindung abgelehnt', exitCode: 1);
        });

        $this->artisan('db:backup', ['--path' => $this->directory])
            ->expectsOutputToContain('Verbindung abgelehnt')
            ->assertFailed();

        $this->assertSame([], File::files($this->directory));
    }

    public function test_mysql_dumps_main_and_designer_database_without_exposing_the_password(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'SAFT2',
            'database.connections.mysql.password' => 'streng-geheim',
            'database.backup.binaries.mysql' => '/snap/bin/mysql.mysqldump',
            'dynamic_data.namespace' => 'SAFT2_app_data',
        ]);
        Process::fake(['*' => Process::result(output: "CREATE DATABASE `SAFT2`;\nCREATE DATABASE `SAFT2_app_data`;\n")]);

        $this->artisan('db:backup', ['--path' => $this->directory])
            ->expectsOutputToContain('Sicherung erstellt')
            ->assertSuccessful();

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command[0] === '/snap/bin/mysql.mysqldump'
                && array_slice($process->command, -3) === ['--databases', 'SAFT2', 'SAFT2_app_data']
                && in_array('--single-transaction', $process->command, true)
                && ! str_contains(implode(' ', $process->command), 'streng-geheim')
                && $process->environment['MYSQL_PWD'] === 'streng-geheim';
        });

        $files = File::files($this->directory);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.sql', $files[0]->getFilename());
        $this->assertStringContainsString('CREATE DATABASE `SAFT2_app_data`', File::get($files[0]->getPathname()));
        $this->assertSame('600', substr(sprintf('%o', $files[0]->getPerms()), -3));
    }

    public function test_mariadb_uses_mariadb_dump_without_mysql_only_options(): void
    {
        config([
            'database.default' => 'mariadb',
            'database.connections.mariadb.database' => 'SAFT2',
            'database.connections.mariadb.password' => 'streng-geheim',
            'database.backup.binaries.mariadb' => null,
            'dynamic_data.namespace' => 'SAFT2_app_data',
        ]);
        Process::fake(['*' => Process::result(output: "CREATE DATABASE `SAFT2`;\n")]);

        $this->artisan('db:backup', ['--path' => $this->directory])
            ->expectsOutputToContain('Sicherung erstellt')
            ->assertSuccessful();

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command[0] === 'mariadb-dump'
                && array_slice($process->command, -3) === ['--databases', 'SAFT2', 'SAFT2_app_data']
                && in_array('--single-transaction', $process->command, true)
                && ! in_array('--set-gtid-purged=OFF', $process->command, true)
                && ! str_contains(implode(' ', $process->command), 'streng-geheim')
                && $process->environment['MYSQL_PWD'] === 'streng-geheim';
        });

        $this->assertStringEndsWith('.sql', File::files($this->directory)[0]->getFilename());
    }

    public function test_mysql_failure_reports_error_and_leaves_no_partial_file(): void
    {
        config(['database.default' => 'mysql']);
        Process::fake(['*' => Process::result(output: '-- teilweise', errorOutput: 'mysqldump: Got error: 1045: Access denied', exitCode: 2)]);

        $this->artisan('db:backup', ['--path' => $this->directory])
            ->expectsOutputToContain('Access denied')
            ->assertFailed();

        $this->assertSame([], File::files($this->directory));
    }

    public function test_rejects_other_database_drivers(): void
    {
        Process::fake();
        config(['database.default' => 'sqlite']);

        $this->artisan('db:backup', ['--path' => $this->directory])->assertFailed();

        Process::assertNothingRan();
    }
}
