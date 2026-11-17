<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Database\DatabaseBackup;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;
use Illuminate\Support\Facades\File;

/** System-Aufgabe: Datenbank sichern wie „php artisan db:backup“ (Ordner storage/backups, DB_BACKUP_METHOD gilt). */
final readonly class DatabaseBackupTask implements TaskType
{
    public function __construct(private DatabaseBackup $backup) {}

    public function key(): string
    {
        return 'db_backup';
    }

    public function label(): string
    {
        return __('tasks.types.db_backup');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.db_backup');
    }

    /** Scheitert die Sicherung, endet der Lauf als fehlgeschlagen (Meldung mit Grund, Glocke für Super-Admins). */
    public function run(array $parameters): TaskResult
    {
        $file = $this->backup->create();
        $kb = (int) round(File::size($file) / 1024);

        return new TaskResult(__('tasks.results.db_backup', ['file' => basename($file), 'kb' => $kb]), ['file' => basename($file), 'kb' => $kb]);
    }
}
