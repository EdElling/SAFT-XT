<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-021: Dateien in Feldern vom Typ „Datei“. Die Datei liegt im Laravel-Speicher (config dynamic_data.files); hier
 * stehen Herkunft (Tabelle, Feld, Datensatz) und Angaben zur Datei. Im Datensatz selbst steht je Datei Kennung und
 * Name. record_id ist leer, solange die Datei hochgeladen, der Datensatz aber noch nicht gespeichert ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('custom_table_id')->constrained()->cascadeOnDelete();
            $table->string('field', 64);
            $table->string('record_id', 64)->nullable();
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->string('name', 255);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['custom_table_id', 'record_id']);
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_files');
    }
};
