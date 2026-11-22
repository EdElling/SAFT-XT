<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR-Rechnung je Vorlage (B-124, D4a): Einstellungen als JSON (enabled, position, amount, currency, reference, message,
 * debtor_*); Texte mit Platzhaltern. Der Zahlungsempfänger kommt aus dem Briefpapier (Absenderdaten, ohne Migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->json('qr_bill')->nullable()->after('email_reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropColumn('qr_bill');
        });
    }
};
