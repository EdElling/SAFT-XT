<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-107, Etappe 7c: Auswertungen des App-Designers – nach dem Erzeugen der App je Wunsch Titel, Beschreibung, Status
 * (wartet, erstellt, nicht möglich), Auswertung und Meldung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_drafts', function (Blueprint $table): void {
            $table->json('reports')->nullable()->after('definition');
        });
    }

    public function down(): void
    {
        Schema::table('app_drafts', function (Blueprint $table): void {
            $table->dropColumn('reports');
        });
    }
};
