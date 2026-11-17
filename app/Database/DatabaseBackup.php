<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Sicherung der gesamten Datenbank inkl. Tabellen des Table-Designers – für „php artisan db:backup“ und die geplante
 * Aufgabe „Datenbank sichern“: PostgreSQL per pg_dump (eine Datenbank, Schema app_data inklusive), MySQL/MariaDB per
 * mysqldump bzw. mariadb-dump oder in PHP (DB_BACKUP_METHOD=php). Liest nur; das Passwort wird nie als Prozessargument
 * übergeben. Dateien: <datenbank>_<JJJJMMTT_HHMMSS>.dump bzw. .sql, Rechte 0600.
 */
final class DatabaseBackup
{
    private const int TIMEOUT_SECONDS = 3600;

    /** Endungen der Sicherungsdateien (Aufräumen löscht nur diese). */
    public const array EXTENSIONS = ['dump', 'sql'];

    public function directory(): string
    {
        return storage_path('backups');
    }

    /**
     * @return string Pfad der neuen Sicherung
     *
     * @throws BackupFailed
     */
    public function create(?string $directory = null, bool $php = false): string
    {
        $connection = (array) config('database.connections.'.config('database.default'));
        $driver = $connection['driver'] ?? null;

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new BackupFailed('db:backup unterstützt PostgreSQL, MySQL und MariaDB.');
        }

        $directory ??= $this->directory();
        File::ensureDirectoryExists($directory, 0700);

        $database = (string) $connection['database'];
        // Eindeutiger Name: Zwei Sicherungen in derselben Sekunde (Befehl und Aufgabe) dürfen sich nie überschreiben –
        // eine fehlgeschlagene würde sonst beim Aufräumen die gültige löschen.
        $base = $directory.DIRECTORY_SEPARATOR.$database.'_'.now()->format('Ymd_His');
        $extension = $driver === 'pgsql' ? '.dump' : '.sql';
        $file = $base.$extension;

        for ($n = 2; File::exists($file); $n++) {
            $file = $base.'_'.$n.$extension;
        }

        $appData = (array) config('database.connections.'.config('dynamic_data.connection'));
        $error = match (true) {
            $driver === 'pgsql' => $this->postgres($connection, $file),
            $php || config('database.backup.method') === 'php' => $this->php($connection, $file),
            default => $this->mysql($connection, $appData, $file, $driver),
        };

        if ($error !== null || ! File::exists($file) || File::size($file) === 0) {
            File::delete($file);

            // Ohne Fehlermeldung des Programms: wenigstens sagen, dass die Datei leer blieb.
            throw new BackupFailed(trim((string) $error) !== '' ? trim((string) $error) : 'Die Sicherungsdatei ist leer – das Programm lieferte keine Ausgabe.');
        }

        File::chmod($file, 0600);

        return $file;
    }

    /**
     * Alte Sicherungen löschen: die neuesten $keep bleiben immer, von den übrigen alle, die älter als $days Tage sind.
     * Nur Dateien mit den Endungen der Sicherung, nur direkt im Ordner.
     *
     * @return list<string> gelöschte Dateinamen
     */
    public function prune(int $days, int $keep, ?string $directory = null): array
    {
        $directory ??= $this->directory();

        if (! File::isDirectory($directory)) {
            return [];
        }

        $files = collect(File::files($directory))
            ->filter(static fn (\SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), self::EXTENSIONS, true))
            ->sortByDesc(static fn (\SplFileInfo $file): int => $file->getMTime())
            ->values();

        $limit = now()->subDays($days)->getTimestamp();
        $deleted = [];

        foreach ($files->slice(max(0, $keep)) as $file) {
            if ($file->getMTime() < $limit && File::delete($file->getPathname())) {
                $deleted[] = $file->getFilename();
            }
        }

        return $deleted;
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
     * MySQL/MariaDB in PHP (SqlDumper): Datenbank von SAFT über die Standardverbindung, Namensraum app_data über seine
     * eigene Verbindung – auch bei getrennten Zugangsdaten je Datenbank.
     *
     * @param  array<string, mixed>  $connection
     * @return string|null Fehlermeldung
     */
    private function php(array $connection, string $file): ?string
    {
        $handle = fopen($file, 'xb');

        if ($handle === false) {
            return "Datei {$file} kann nicht angelegt werden.";
        }

        File::chmod($file, 0600);

        try {
            $dumper = app(SqlDumper::class);
            $dumper->dump(DB::connection(), (string) $connection['database'], $handle);
            $dumper->dump(DB::connection((string) config('dynamic_data.connection')), (string) config('dynamic_data.namespace'), $handle);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            fclose($handle);
        }
    }

    /**
     * MySQL/MariaDB mit mysqldump bzw. mariadb-dump: je Verbindung ein Aufruf, die Ausgabe geht laufend in eine Datei.
     * Mit denselben Zugangsdaten ein Aufruf für beide Datenbanken; hat app_data eigenen Server oder Benutzer, zwei.
     * (Nicht --result-file: eingeschränkte Programme wie das Snap-Paket von MySQL dürfen nicht selbst Dateien anlegen.)
     *
     * @param  array<string, mixed>  $connection  Standardverbindung
     * @param  array<string, mixed>  $appData  Verbindung des Namensraums app_data
     * @return string|null Fehlermeldung
     */
    private function mysql(array $connection, array $appData, string $file, string $driver): ?string
    {
        $handle = fopen($file, 'xb');

        if ($handle === false) {
            return "Datei {$file} kann nicht angelegt werden.";
        }

        File::chmod($file, 0600);
        $namespace = (string) config('dynamic_data.namespace');
        $separate = array_map('strval', [$appData['host'] ?? '', $appData['port'] ?? '', $appData['username'] ?? ''])
            !== array_map('strval', [$connection['host'] ?? '', $connection['port'] ?? '', $connection['username'] ?? '']);
        $parts = $separate
            ? [[$connection, [(string) $connection['database']]], [$appData, [$namespace]]]
            : [[$connection, [(string) $connection['database'], $namespace]]];

        try {
            foreach ($parts as [$credentials, $databases]) {
                if (($error = $this->mysqlDump($credentials, $databases, $handle, $driver)) !== null) {
                    return $error;
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  list<string>  $databases
     * @param  resource  $handle
     * @return string|null Fehlermeldung
     */
    private function mysqlDump(array $connection, array $databases, $handle, string $driver): ?string
    {
        $errors = '';
        $binary = $driver === 'mariadb' ? $this->binary('mariadb', 'mariadb-dump') : $this->binary('mysql', 'mysqldump');
        $variable = $driver === 'mariadb' ? 'MARIADB_DUMP_BINARY' : 'MYSQL_DUMP_BINARY';

        try {
            $process = Process::timeout(self::TIMEOUT_SECONDS)
                ->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
                ->start([
                    $binary,
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
                    ...$databases,
                ]);
        } catch (Throwable $e) {
            // Programm fehlt oder darf nicht gestartet werden (z. B. proc_open gesperrt).
            return "„{$binary}“ lässt sich nicht starten ({$e->getMessage()}). Pfad in {$variable} eintragen, DB_BACKUP_METHOD=php setzen oder die Datenbanksicherung des Hosters verwenden.";
        }

        $result = $process->wait(static function (string $type, string $output) use ($handle, &$errors): void {
            if ($type === 'out') {
                fwrite($handle, $output);
            } else {
                $errors .= $output;
            }
        });

        if (! $result->failed()) {
            return null;
        }

        $message = trim($errors !== '' ? $errors : $result->errorOutput());

        return match (true) {
            // Alte mysqldump-Fassungen (z. B. 5.6 bei Strato) kennen die unsichtbaren Spalten von MariaDB nicht.
            str_contains($message, 'field count') => $message.' – dieses mysqldump ist zu alt für die Tabellen; DB_BACKUP_METHOD=php setzen.',
            $message !== '' => $message,
            $result->exitCode() === 127 => "„{$binary}“ nicht gefunden. Pfad in {$variable} eintragen (z. B. /usr/bin/mysqldump), DB_BACKUP_METHOD=php setzen oder die Datenbanksicherung des Hosters verwenden.",
            default => "„{$binary}“ endete mit Exit-Code {$result->exitCode()} ohne Fehlermeldung.",
        };
    }

    /** Programmpfad aus PGSQL_DUMP_BINARY, MYSQL_DUMP_BINARY bzw. MARIADB_DUMP_BINARY, sonst der Standardname. */
    private function binary(string $driver, string $default): string
    {
        $configured = config("database.backup.binaries.{$driver}");

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }
}
