<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            // Eindeutigkeit gilt künftig je konfiguriertem IdP, nicht je Provider-Typ
            // (mehrere Entra-Mandanten möglich).
            $table->foreignId('identity_provider_id')
                ->nullable()
                ->after('user_id')
                ->constrained('identity_providers')
                ->cascadeOnDelete();

            $table->unique(['identity_provider_id', 'provider_user_id']);
            $table->unique(['user_id', 'identity_provider_id']);

            // Erst danach die alten Indizes löschen: MySQL nutzt (user_id, …) zugleich für den
            // Fremdschlüssel auf users und lehnt das Löschen sonst ab.
            $table->dropUnique(['provider', 'provider_user_id']);
            $table->dropUnique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);

            $table->dropUnique(['identity_provider_id', 'provider_user_id']);
            $table->dropUnique(['user_id', 'identity_provider_id']);
            $table->dropConstrainedForeignId('identity_provider_id');
        });
    }
};
