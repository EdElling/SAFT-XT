<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-107, Etappe 5: Schnittstellen zwischen Apps. Eine App gibt einzelne Tabellen mit ausgewählten Feldern für alle
 * anderen Apps frei (nur lesend); die Version der Schnittstelle steigt bei unverträglichen Änderungen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->boolean('is_shared')->default(false)->after('is_active');
        });

        Schema::table('custom_fields', function (Blueprint $table): void {
            $table->boolean('is_shared')->default(false)->after('is_active');
        });

        Schema::table('apps', function (Blueprint $table): void {
            $table->unsignedInteger('interface_version')->default(1)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('apps', fn (Blueprint $table) => $table->dropColumn('interface_version'));
        Schema::table('custom_fields', fn (Blueprint $table) => $table->dropColumn('is_shared'));
        Schema::table('custom_tables', fn (Blueprint $table) => $table->dropColumn('is_shared'));
    }
};
