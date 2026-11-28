<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Neue System-Aufgabe „Sitzungen aufräumen“ (stündlich zur Minute 40, eingeschaltet; B-177): ersetzt Laravels
 * Lotterie, die auf MySQL/MariaDB in Deadlocks geriet. Eine vorhandene bleibt unverändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('scheduled_tasks')->where('type', 'sessions_prune')->where('is_system', true)->exists()) {
            return;
        }

        $now = now();
        DB::table('scheduled_tasks')->insert([
            'name' => null, 'type' => 'sessions_prune', 'parameters' => null,
            'frequency' => 'hourly', 'interval_hours' => null, 'at_time' => '00:40', 'weekday' => null,
            'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('is_system', true)->where('type', 'sessions_prune')->delete();
    }
};
