<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** KI-Anfragen pro Tag je Person: leer = Standard der KI-Einstellungen, 0 = gesperrt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ai_daily_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ai_daily_limit');
        });
    }
};
