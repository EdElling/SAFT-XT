<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-107, Etappe 2: zuletzt gewählte App je Person (über die Abmeldung hinaus) und Handbücher je App (Markdown je
 * Sprache; erscheinen unter „Dokumentation“ und im Hilfe-Assistenten, solange die App die aktuelle ist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_app_id')->nullable()->constrained('apps')->nullOnDelete();
        });

        Schema::create('app_manuals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->longText('content');
            $table->timestamps();
            $table->unique(['app_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_manuals');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_app_id');
        });
    }
};
