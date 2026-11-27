<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sammeländerung S3 „Rückgängig“ (B-133, BULK_EDIT.md §13): je erfolgreicher Sammelaktion (ändern, löschen,
 * wiederherstellen, Rücknahme) ein Kopf mit Kennung, Person, Art und Angaben je Feld und den Schlüsseln der
 * geänderten Datensätze. Die alten Werte stehen im Änderungsprotokoll (Kennung „run“), nicht hier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('run', 64)->unique();
            $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
            $table->foreignId('custom_table_id')->constrained('custom_tables')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation', 16);
            $table->json('field_changes')->nullable();
            $table->json('record_ids');
            $table->unsignedInteger('count');
            $table->json('result')->nullable();
            $table->foreignId('undo_of_id')->nullable()->constrained('bulk_changes')->nullOnDelete();
            $table->foreignId('undone_by_id')->nullable()->constrained('bulk_changes')->nullOnDelete();
            $table->timestamps();

            $table->index(['custom_table_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_changes');
    }
};
