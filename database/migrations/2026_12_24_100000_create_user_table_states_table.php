<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-027: Filter, Sortierung, Suche und Zeilen pro Seite einer Datenansicht je Person dauerhaft (über die Sitzung
 * hinaus). state: nur Abweichungen von der Vorgabe; kein Eintrag = Vorgabe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_table_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('table_key', 191);
            $table->json('state');
            $table->timestamps();

            $table->unique(['user_id', 'table_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_table_states');
    }
};
