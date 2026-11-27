<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sammeländerung (B-133, S1): Schalter „Sammeländerung erlauben“ in den Stammdaten der Tabelle, Vorgabe an. Ein
 * eigenes Recht gibt es nicht; es gilt das Recht „ändern“ der Tabelle (BULK_EDIT.md §11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->boolean('bulk_enabled')->default(true)->after('import_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropColumn('bulk_enabled');
        });
    }
};
