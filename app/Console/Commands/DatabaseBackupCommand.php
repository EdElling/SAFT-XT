<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Sichert die gesamte Datenbank inkl. Tabellen des Table-Designers:
 * PostgreSQL per pg_dump (eine Datenbank, Schema app_data inklusive), MySQL per mysqldump
 * (Hauptdatenbank und <DB>_app_data in einer Datei). Der Befehl liest nur; das Passwort wird
 * nie als Prozessargument übergeben.
 */
#[Signature('db:backup {--path= : Zielordner (Standard: storage/backups)}')]
#[Description('Erstellt eine Sicherung der Datenbank')]
class DatabaseBackupCommand extends Command
{
    private const int TIMEOUT_SECONDS = 3600;

    public function handle(): int
    {
        $connection = (array) config('database.connections.'.config('database.default'));
        $driver = $connection['driver'] ?? null;

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            $this->error('db:backup unterstützt PostgreSQL, MySQL und MariaDB.');

            return self::FAILURE;
        }

        $directory = (string) ($this->option('path') ?: storage_path('backups'));
        File::ensureDirectoryExists($directory, 0700);

        $database = (string) $connection['database'];
        $file = $directory.DIRECTORY_SEPARATOR.$database.'_'.now()->format('Ymd_His').($driver === 'pgsql' ? '.dump' : '.sql');

        $error = $driver === 'pgsql' ? $this->postgres($connection, $file) : $this->mysql($connection, $file, $driver);

        if ($error !== null || ! File::exists($file) || File::size($file) === 0) {
            File::delete($file);
            $this->error('Sicherung fehlgeschlagen: '.trim((string) $error));

            return self::FAILURE;
        }

        File::chmod($file, 0600);
        $this->info('Sicherung erstellt: '.$file.' ('.round(File::size($file) / 1024).' KB)');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return string|null Fehlermeldung
     */
    private function postgres(array $connection, string $file): ?string
    {
        $result = Process::timeout(self::TIMEOUT_SECONDS)
            ->env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->run([
                $this->binary('pgsql', 'pg_dump'),
                '--format=custom',
                '--no-password',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--file='.$file,
                (string) $connection['database'],
            ]);

        return $result->failed() ? $result->errorOutput() : null;
    }

    /**
     * mysqldump bzw. mariadb-dump schreibt nach stdout; SAFT schreibt die Datei selbst. So
     * funktioniert auch ein mysqldump aus einem Snap, der keine Dateien im Home-Verzeichnis anlegen darf.
     *
     * @param  array<string, mixed>  $connection
     * @param  'mysql'|'mariadb'  $driver
     * @return string|null Fehlermeldung
     */
    private function mysql(array $connection, string $file, string $driver): ?string
    {
        $handle = fopen($file, 'xb');

        if ($handle === false) {
            return "Datei {$file} kann nicht angelegt werden.";
        }

        File::chmod($file, 0600);
        $errors = '';

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)
                ->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
                ->start([
                    $driver === 'mariadb' ? $this->binary('mariadb', 'mariadb-dump') : $this->binary('mysql', 'mysqldump'),
                    '--host='.$connection['host'],
                    '--port='.$connection['port'],
                    '--user='.$connection['username'],
                    '--single-transaction',
                    '--routines',
                    '--triggers',
                    '--hex-blob',
                    '--no-tablespaces',
                    // GTID-Option gibt es nur bei MySQL.
                    ...($driver === 'mysql' ? ['--set-gtid-purged=OFF'] : []),
                    '--databases',
                    (string) $connection['database'],
                    (string) config('dynamic_data.namespace'),
                ])
                ->wait(static function (string $type, string $output) use ($handle, &$errors): void {
                    if ($type === 'out') {
                        fwrite($handle, $output);
                    } else {
                        $errors .= $output;
                    }
                });
        } finally {
            fclose($handle);
        }

        return $result->failed() ? ($errors !== '' ? $errors : $result->errorOutput()) : null;
    }

    /** Programmpfad aus PGSQL_DUMP_BINARY, MYSQL_DUMP_BINARY bzw. MARIADB_DUMP_BINARY, sonst der Standardname. */
    private function binary(string $driver, string $default): string
    {
        $configured = config("database.backup.binaries.{$driver}");

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }
}
