<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erzeugte Dokumente (B-124, D1c): Eine Datei im Dateifeld, die SAFT aus einer Vorlage erzeugt hat, trägt die Vorlage
 * und den Prüfwert (SHA-256) – sie ist schreibgeschützt. Die Vorlage nennt das Dateifeld für die Ablage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_files', function (Blueprint $table): void {
            $table->foreignId('document_template_id')->nullable()->after('uploaded_by')->constrained('document_templates')->nullOnDelete();
            $table->string('sha256', 64)->nullable()->after('document_template_id');
        });

        Schema::table('document_templates', function (Blueprint $table): void {
            // Dateifeld der Grundtabelle für die Ablage; leer = nur herunterladen.
            $table->string('file_field', 64)->nullable()->after('language_field');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropColumn('file_field');
        });

        Schema::table('record_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_template_id');
            $table->dropColumn('sha256');
        });
    }
};
