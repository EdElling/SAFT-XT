<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Neue System-Aufgaben: Datenbank sichern (02:30), Sicherungen aufräumen (02:45) und Demo-Daten zurücksetzen (04:00,
 * ausgeschaltet). Vorhandene Aufgaben gleicher Art bleiben unverändert.
 */
return new class extends Migration
{
    private const array TASKS = [
        ['type' => 'db_backup', 'at_time' => '02:30', 'parameters' => null, 'is_active' => true],
        ['type' => 'backup_prune', 'at_time' => '02:45', 'parameters' => ['days' => 14, 'keep' => 3], 'is_active' => true],
        ['type' => 'demo_reset', 'at_time' => '04:00', 'parameters' => ['demos' => ['shop', 'lager', 'forschung'], 'backup' => true], 'is_active' => false],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::TASKS as $task) {
            if (DB::table('scheduled_tasks')->where('type', $task['type'])->where('is_system', true)->exists()) {
                continue;
            }

            DB::table('scheduled_tasks')->insert([
                'name' => null, 'type' => $task['type'], 'parameters' => $task['parameters'] === null ? null : json_encode($task['parameters']),
                'frequency' => 'daily', 'interval_hours' => null, 'at_time' => $task['at_time'], 'weekday' => null,
                'is_active' => $task['is_active'], 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('is_system', true)->whereIn('type', array_column(self::TASKS, 'type'))->delete();
    }
};
