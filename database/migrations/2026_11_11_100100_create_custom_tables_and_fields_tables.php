<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_tables', function (Blueprint $table): void {
            $table->id();
            // Technischer Name im Namensraum app_data; nach dem Anlegen unveränderlich.
            $table->string('name', 63)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('icon', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_table_id')->constrained('custom_tables')->cascadeOnDelete();
            $table->string('name', 63);
            $table->string('label');
            $table->string('type', 20);
            // Typabhängig: Länge, Nachkommastellen, Select-Optionen, Relationsziel.
            $table->jsonb('options')->nullable();
            // Strukturierte Validierung (min, max, email …) – keine freien Laravel-Regeln.
            $table->jsonb('validation_rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_sortable')->default(false);
            $table->boolean('show_in_table')->default(true);
            $table->boolean('show_in_form')->default(true);
            // Deaktivierte Felder: Spalte und Daten bleiben erhalten, UI blendet aus.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['custom_table_id', 'name']);
            $table->index(['custom_table_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('custom_tables');
    }
};
