<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formate je Vorlage (B-124, D4b): pdf (Vorgabe), docx (Word) oder both. Bestehende Vorlagen bleiben bei PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->string('formats', 10)->default('pdf')->after('qr_bill');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropColumn('formats');
        });
    }
};
