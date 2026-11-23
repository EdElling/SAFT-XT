<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_provider_role_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_provider_id')->constrained('identity_providers')->cascadeOnDelete();
            // Entra: Gruppen-Object-ID oder App-Rollen-Wert; SAML: Attributwert.
            $table->string('external_value', 500);
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['identity_provider_id', 'external_value', 'role_id'], 'idp_role_mappings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_provider_role_mappings');
    }
};
