<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Neue System-Aufgabe „Dateien aufräumen“ (03:00, B-021); eine vorhandene bleibt unverändert. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('scheduled_tasks')->where('type', 'files_prune')->where('is_system', true)->exists()) {
            return;
        }

        $now = now();
        DB::table('scheduled_tasks')->insert([
            'name' => null, 'type' => 'files_prune', 'parameters' => null,
            'frequency' => 'daily', 'interval_hours' => null, 'at_time' => '03:00', 'weekday' => null,
            'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('is_system', true)->where('type', 'files_prune')->delete();
    }
};
