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
    /** @var list<array{type: string, at_time: string, parameters: array<string, mixed>|null}> */
    public const array TASKS = [
        ['type' => 'snapshot_prune', 'at_time' => '03:15', 'parameters' => null],
        ['type' => 'task_runs_prune', 'at_time' => '03:30', 'parameters' => ['days' => 90]],
    ];

    public function run(): void
    {
        foreach (self::TASKS as $task) {
            ScheduledTask::query()->firstOrCreate(
                ['type' => $task['type'], 'is_system' => true],
                ['frequency' => 'daily', 'at_time' => $task['at_time'], 'parameters' => $task['parameters'], 'is_active' => true],
            );
        }
    }
}
