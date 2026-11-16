<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Für Dashboard-Abfragen ("meine Änderungen", Anmeldestatistik) bei wachsendem Log.
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_log_causer_created_index');
            $table->index(['event', 'created_at'], 'activity_log_event_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_causer_created_index');
            $table->dropIndex('activity_log_event_created_index');
        });
    }
};
