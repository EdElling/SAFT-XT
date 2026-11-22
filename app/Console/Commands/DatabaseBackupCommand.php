<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Database\BackupFailed;
use App\Database\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Sichert die gesamte Datenbank inkl. Tabellen des Table-Designers und die Dateien aus Dateifeldern und Dokumenten (siehe DatabaseBackup). */
#[Signature('db:backup {--path= : Zielordner (Standard: storage/backups)} {--php : MySQL/MariaDB: in PHP sichern statt mit mysqldump} {--without-files : Dateien aus Dateifeldern und Briefpapier nicht sichern}')]
#[Description('Erstellt eine Sicherung der Datenbank')]
class DatabaseBackupCommand extends Command
{
    public function handle(DatabaseBackup $backup): int
    {
        try {
            $file = $backup->create($this->option('path') ?: null, (bool) $this->option('php'));
            $files = $this->option('without-files') ? null : $backup->backupFiles($file);
        } catch (BackupFailed $e) {
            $this->error(str_starts_with($e->getMessage(), 'db:backup') ? $e->getMessage() : 'Sicherung fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sicherung erstellt: '.$file.' ('.round(File::size($file) / 1024).' KB)');

        if ($files !== null) {
            $this->info('Dateien gesichert: '.$files.' ('.round(File::size($files) / 1024).' KB)');
        } elseif (! $this->option('without-files') && ! $backup->filesAreLocal()) {
            $this->warn('Die Dateien aus Dateifeldern liegen nicht auf diesem Server (z. B. S3) und sind nicht enthalten – sichern Sie sie dort.');
        }

        return self::SUCCESS;
    }
}
