<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plugins ein- und ausschalten (B-030): je Plugin ein Eintrag, sobald es einmal umgeschaltet wurde; ohne Eintrag aktiv. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_states', function (Blueprint $table): void {
            $table->id();
            $table->string('plugin_id', 100)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_states');
    }
};
