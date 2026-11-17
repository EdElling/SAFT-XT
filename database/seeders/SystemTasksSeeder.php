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
    /** @var list<array{type: string, at_time: string, parameters: array<string, mixed>|null, is_active?: bool}> */
    public const array TASKS = [
        ['type' => 'db_backup', 'at_time' => '02:30', 'parameters' => null],
        ['type' => 'backup_prune', 'at_time' => '02:45', 'parameters' => ['days' => 14, 'keep' => 3]],
        ['type' => 'snapshot_prune', 'at_time' => '03:15', 'parameters' => null],
        ['type' => 'task_runs_prune', 'at_time' => '03:30', 'parameters' => ['days' => 90]],
        // Zerstörerisch: ausgeschaltet, und auch eingeschaltet nur mit DEMO_RESET=true.
        ['type' => 'demo_reset', 'at_time' => '04:00', 'parameters' => ['demos' => ['shop', 'lager', 'forschung'], 'backup' => true], 'is_active' => false],
    ];

    public function run(): void
    {
        foreach (self::TASKS as $task) {
            ScheduledTask::query()->firstOrCreate(
                ['type' => $task['type'], 'is_system' => true],
                ['frequency' => 'daily', 'at_time' => $task['at_time'], 'parameters' => $task['parameters'], 'is_active' => $task['is_active'] ?? true],
            );
        }
    }
}
