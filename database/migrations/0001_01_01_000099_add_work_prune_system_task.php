<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Neue System-Aufgabe „Arbeiten aufräumen“ (stündlich zur Minute 20, eingeschaltet; Fundament 2, F3a); eine
 * vorhandene bleibt unverändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('scheduled_tasks')->where('type', 'work_prune')->where('is_system', true)->exists()) {
            return;
        }

        $now = now();
        DB::table('scheduled_tasks')->insert([
            'name' => null, 'type' => 'work_prune', 'parameters' => json_encode(['days' => 30]),
            'frequency' => 'hourly', 'interval_hours' => null, 'at_time' => '00:20', 'weekday' => null,
            'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('is_system', true)->where('type', 'work_prune')->delete();
    }
};
