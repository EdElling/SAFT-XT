<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Neue System-Aufgabe „Änderungsprotokoll kürzen“ (03:45, ausgeschaltet; Fundament 2, F0); eine vorhandene bleibt
 * unverändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('scheduled_tasks')->where('type', 'activity_prune')->where('is_system', true)->exists()) {
            return;
        }

        $now = now();
        DB::table('scheduled_tasks')->insert([
            'name' => null, 'type' => 'activity_prune', 'parameters' => json_encode(['days' => 365, 'auth_days' => 90]),
            'frequency' => 'daily', 'interval_hours' => null, 'at_time' => '03:45', 'weekday' => null,
            'is_active' => false, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('is_system', true)->where('type', 'activity_prune')->delete();
    }
};
