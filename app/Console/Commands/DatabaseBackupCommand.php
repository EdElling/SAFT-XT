<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Database\BackupFailed;
use App\Database\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Sichert die gesamte Datenbank inkl. Tabellen des Table-Designers (siehe DatabaseBackup). */
#[Signature('db:backup {--path= : Zielordner (Standard: storage/backups)} {--php : MySQL/MariaDB: in PHP sichern statt mit mysqldump}')]
#[Description('Erstellt eine Sicherung der Datenbank')]
class DatabaseBackupCommand extends Command
{
    public function handle(DatabaseBackup $backup): int
    {
        try {
            $file = $backup->create($this->option('path') ?: null, (bool) $this->option('php'));
        } catch (BackupFailed $e) {
            $this->error(str_starts_with($e->getMessage(), 'db:backup') ? $e->getMessage() : 'Sicherung fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sicherung erstellt: '.$file.' ('.round(File::size($file) / 1024).' KB)');

        return self::SUCCESS;
    }
}
