<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ScheduledTask;
use Illuminate\Database\Seeder;

/**
 * System-Aufgaben (Administration › Geplante Aufgaben): legt fehlende an, ändert vorhandene nicht
 * (Zeitplan, Aktivierung und Parameter darf der Administrator anpassen). Wiederholbar.
 */
class SystemTasksSeeder extends Seeder
{
    /** @var list<array{type: string, at_time: string, parameters: array<string, mixed>|null, is_active?: bool, frequency?: string}> */
    public const array TASKS = [
        ['type' => 'db_backup', 'at_time' => '02:30', 'parameters' => null],
        ['type' => 'backup_prune', 'at_time' => '02:45', 'parameters' => ['days' => 14, 'keep' => 3]],
        ['type' => 'files_prune', 'at_time' => '03:00', 'parameters' => null],
        ['type' => 'snapshot_prune', 'at_time' => '03:15', 'parameters' => null],
        ['type' => 'task_runs_prune', 'at_time' => '03:30', 'parameters' => ['days' => 90]],
        // Löscht Personendaten endgültig: ausgeschaltet, bis die Administration die Fristen bestätigt (Hinweis auf dem Dashboard).
        ['type' => 'activity_prune', 'at_time' => '03:45', 'parameters' => ['days' => 365, 'auth_days' => 90], 'is_active' => false],
        // Zerstörerisch: ausgeschaltet, und auch eingeschaltet nur mit DEMO_RESET=true.
        // Arbeiten (Fundament 2, F3a): stündlich, damit Ergebnisdateien nicht lange über ihre Frist liegen bleiben.
        ['type' => 'work_prune', 'at_time' => '00:20', 'parameters' => ['days' => 30], 'frequency' => 'hourly'],
        // Sitzungen (B-177): ersetzt Laravels Lotterie, stündlich.
        ['type' => 'sessions_prune', 'at_time' => '00:40', 'parameters' => null, 'frequency' => 'hourly'],
        ['type' => 'demo_reset', 'at_time' => '04:00', 'parameters' => ['demos' => ['shop', 'lager', 'forschung'], 'backup' => true], 'is_active' => false],
    ];

    public function run(): void
    {
        foreach (self::TASKS as $task) {
            ScheduledTask::query()->firstOrCreate(
                ['type' => $task['type'], 'is_system' => true],
                ['frequency' => $task['frequency'] ?? 'daily', 'at_time' => $task['at_time'], 'parameters' => $task['parameters'], 'is_active' => $task['is_active'] ?? true],
            );
        }
    }
}
