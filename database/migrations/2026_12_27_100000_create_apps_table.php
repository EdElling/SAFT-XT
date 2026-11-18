<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-107: Apps (Fachanwendungen). Eine App fasst Tabellen, Menüs, Auswertungen, Rollen und Aufgaben zusammen, die
 * fachlich zusammengehören (siehe docs/concept/APPS.md). uuid ist der stabile Schlüssel für Pakete und Updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('prefix', 30)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
