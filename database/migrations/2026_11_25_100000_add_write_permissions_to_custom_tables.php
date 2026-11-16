<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table-Connector: Anlegen, Bearbeiten und Löschen je angebundener Tabelle bewusst freischalten
 * (Vorgabe aus). Für Designer-Tabellen ohne Bedeutung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->boolean('allow_create')->default(false)->after('export_enabled');
            $table->boolean('allow_update')->default(false)->after('allow_create');
            $table->boolean('allow_delete')->default(false)->after('allow_update');
        });
    }

    public function down(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropColumn(['allow_create', 'allow_update', 'allow_delete']);
        });
    }
};
