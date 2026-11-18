<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Database\DatabaseBackup;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\File;

/**
 * System-Aufgabe: Datenbank sichern wie „php artisan db:backup“ (Ordner storage/backups, DB_BACKUP_METHOD gilt), mit den
 * Dateien aus Dateifeldern als ZIP daneben (abschaltbar).
 */
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
        return [
            Toggle::make('files')
                ->label(__('tasks.fields.backup_files'))
                ->helperText(__('tasks.fields.backup_files_help'))
                ->default(true),
        ];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.db_backup').(self::withFiles($parameters) ? __('tasks.describe_type.db_backup_files') : '');
    }

    /** Scheitert die Sicherung, endet der Lauf als fehlgeschlagen (Meldung mit Grund, Glocke für Super-Admins). */
    public function run(array $parameters): TaskResult
    {
        $file = $this->backup->create();
        $kb = (int) round(File::size($file) / 1024);
        $message = __('tasks.results.db_backup', ['file' => basename($file), 'kb' => $kb]);
        $details = ['file' => basename($file), 'kb' => $kb];

        if (self::withFiles($parameters)) {
            if (! $this->backup->filesAreLocal()) {
                $message .= ' '.__('tasks.results.db_backup_files_remote');
            } elseif (($files = $this->backup->backupFiles($file)) !== null) {
                $details['files'] = basename($files);
                $details['files_kb'] = (int) round(File::size($files) / 1024);
                $message .= ' '.__('tasks.results.db_backup_files', ['file' => $details['files'], 'kb' => $details['files_kb']]);
            }
        }

        return new TaskResult($message, $details);
    }

    /** @param  array<string, mixed>  $parameters */
    public static function withFiles(array $parameters): bool
    {
        return (bool) ($parameters['files'] ?? true);
    }
}
