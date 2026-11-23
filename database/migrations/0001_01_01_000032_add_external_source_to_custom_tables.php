<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table-Connector: Tabellen und Views externer Datenbanken als CustomTable mit Quelle „extern“.
 * Bestehende Tabellen bleiben Designer-Tabellen. Eine Verbindung lässt sich nicht löschen,
 * solange angebundene Tabellen sie nutzen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->string('source', 20)->default('designer')->after('name');
            $table->foreignId('database_connection_id')->nullable()->after('source')->constrained('database_connections')->restrictOnDelete();
            $table->string('external_schema', 128)->nullable()->after('database_connection_id');
            $table->string('external_table', 128)->nullable()->after('external_schema');
            $table->string('external_kind', 10)->nullable()->after('external_table');
            // Schlüsselspalte(n); leer = ohne Schlüssel (nur Liste und Export).
            $table->json('key_columns')->nullable()->after('external_kind');
            $table->index('source');
        });

        Schema::table('custom_fields', function (Blueprint $table): void {
            // Originaltyp der Datenbank, nur zur Anzeige (z. B. "character varying(200)").
            $table->string('external_type', 128)->nullable()->after('type');
            $table->boolean('is_read_only')->default(false)->after('is_active');
            // Von der Datenbank gefüllt: Sequenz, Identity, berechnete Spalte.
            $table->boolean('is_generated')->default(false)->after('is_read_only');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table): void {
            $table->dropColumn(['external_type', 'is_read_only', 'is_generated']);
        });

        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropConstrainedForeignId('database_connection_id');
            $table->dropColumn(['source', 'external_schema', 'external_table', 'external_kind', 'key_columns']);
        });
    }
};
