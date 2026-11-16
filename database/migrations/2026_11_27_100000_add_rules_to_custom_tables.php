<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regeln auf Tabellenebene (z. B. „Ende ≥ Beginn“, App\DynamicData\Rules\TableRule) und die
 * CHECK-Regeln angebundener Tabellen, wie die externe Datenbank sie meldet (Abgleich, Fehlermeldungen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->json('validation_rules')->nullable();
            $table->json('check_constraints')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropColumn(['validation_rules', 'check_constraints']);
        });
    }
};
