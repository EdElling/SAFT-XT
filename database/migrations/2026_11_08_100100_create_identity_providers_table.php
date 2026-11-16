<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            // Nicht geheime Konfiguration (Tenant, Client-ID, Entity-IDs, Mapping …).
            $table->json('settings')->nullable();
            // Geheimnisse, im Model per encrypted:array verschlüsselt -> text.
            $table->text('secrets')->nullable();
            $table->boolean('link_by_email')->default(true);
            $table->boolean('auto_provision')->default(false);
            $table->foreignId('default_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->json('allowed_domains')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_successful')->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_providers');
    }
};
