<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Sicherung der gesamten Datenbank inkl. Tabellen des Table-Designers – für „php artisan db:backup“ und die geplante
 * Aufgabe „Datenbank sichern“: PostgreSQL per pg_dump (eine Datenbank, Schema app_data inklusive), MySQL/MariaDB per
 * mysqldump bzw. mariadb-dump oder in PHP (DB_BACKUP_METHOD=php). Liest nur; das Passwort wird nie als Prozessargument
 * übergeben. Dateien: <datenbank>_<JJJJMMTT_HHMMSS>.dump bzw. .sql, Rechte 0600. Dazu die Dateien aus Dateifeldern
 * (B-021, sofern sie lokal liegen) und das Briefpapier der Dokumente als <gleicher Name>_files.zip; Sicherung und ZIP
 * bilden ein Paar.
 */
final class DatabaseBackup
{
    private const int TIMEOUT_SECONDS = 3600;

    /** Endungen der Sicherungsdateien (Aufräumen löscht nur diese). */
    public const array EXTENSIONS = ['dump', 'sql'];

    /** Zusatz der ZIP-Datei mit den Dateien aus Dateifeldern. */
    public const string FILES_SUFFIX = '_files.zip';

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

    /** Verzeichnis auf der Disk „local“ mit Briefpapier, Logos und Hausschriften (Dokumente). */
    public const string DOCUMENTS_DIRECTORY = 'documents';

    /**
     * Dateien neben die Sicherung $dump legen (<name>_files.zip): die Dateien aus Dateifeldern (ohne Vorschaubilder),
     * sofern sie lokal liegen (S3: siehe filesAreLocal()), und die Dateien der Dokumente (Briefpapier, Logos, Schriften).
     * Pfade wie im Speicher, damit sich das ZIP in dessen Wurzel (storage/app/private) entpacken lässt. null, wenn es
     * nichts zu sichern gibt.
     *
     * @throws BackupFailed
     */
    public function backupFiles(string $dump): ?string
    {
        /** @var array<string, string> $files Pfad im ZIP => Datei */
        $files = [];
        $sources = [[Storage::disk('local'), self::DOCUMENTS_DIRECTORY]];

        if ($this->filesAreLocal()) {
            array_unshift($sources, [Storage::disk((string) config('dynamic_data.files.disk', 'local')), trim((string) config('dynamic_data.files.directory', 'files'), '/')]);
        }

        foreach ($sources as [$disk, $directory]) {
            $root = $disk->path($directory);

            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.thumb.jpg')) {
                    $files[$directory.'/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getPathname();
                }
            }
        }

        if ($files === []) {
            return null;
        }

        $zipFile = (string) preg_replace('/\.(dump|sql)$/', '', $dump).self::FILES_SUFFIX;
        $zip = new ZipArchive;

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new BackupFailed('Die ZIP-Datei mit den Dateien lässt sich nicht anlegen: '.$zipFile);
        }

        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }

        if (! $zip->close() || ! File::exists($zipFile)) {
            File::delete($zipFile);

            throw new BackupFailed('Die ZIP-Datei mit den Dateien konnte nicht geschrieben werden.');
        }

        File::chmod($zipFile, 0600);

        return $zipFile;
    }

    /** Name der Sicherung, zu der eine Datei gehört (Datenbanksicherung und ZIP mit den Dateien teilen ihn). */
    public static function setName(string $fileName): string
    {
        return str_ends_with($fileName, self::FILES_SUFFIX)
            ? substr($fileName, 0, -strlen(self::FILES_SUFFIX))
            : pathinfo($fileName, PATHINFO_FILENAME);
    }

    /** Liegen die Dateien aus Dateifeldern auf dem Server (sonst z. B. in S3 und dort zu sichern)? */
    public function filesAreLocal(): bool
    {
        return config('filesystems.disks.'.config('dynamic_data.files.disk', 'local').'.driver') === 'local';
    }

    /**
     * Alte Sicherungen löschen: die neuesten $keep bleiben immer, von den übrigen alle, die älter als $days Tage sind.
     * Eine Sicherung und ihr ZIP mit den Dateien zählen als eine Sicherung und werden zusammen gelöscht. Nur Dateien
     * mit den Endungen der Sicherung, nur direkt im Ordner.
     *
     * @return list<string> gelöschte Dateinamen
     */
    public function prune(int $days, int $keep, ?string $directory = null): array
    {
        $directory ??= $this->directory();

        if (! File::isDirectory($directory)) {
            return [];
        }

        $sets = collect(File::files($directory))
            ->filter(static fn (\SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)
                || str_ends_with($file->getFilename(), self::FILES_SUFFIX))
            ->groupBy(static fn (\SplFileInfo $file): string => self::setName($file->getFilename()))
            ->sortByDesc(static fn ($files): int => (int) $files->max(static fn (\SplFileInfo $file): int => $file->getMTime()))
            ->values();

        $limit = now()->subDays($days)->getTimestamp();
        $deleted = [];

        foreach ($sets->slice(max(0, $keep)) as $files) {
            if ($files->max(static fn (\SplFileInfo $file): int => $file->getMTime()) >= $limit) {
                continue;
            }

            foreach ($files as $file) {
                if (File::delete($file->getPathname())) {
                    $deleted[] = $file->getFilename();
                }
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
