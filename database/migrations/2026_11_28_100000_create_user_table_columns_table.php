<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spaltenauswahl je Benutzer und Tabelle (dauerhaft, über die Sitzung hinaus).
 * columns: Spaltenname => sichtbar (nur Abweichungen von der Vorgabe der Tabelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_table_columns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('table_key', 191);
            $table->json('columns');
            $table->timestamps();

            $table->unique(['user_id', 'table_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_table_columns');
    }
};
