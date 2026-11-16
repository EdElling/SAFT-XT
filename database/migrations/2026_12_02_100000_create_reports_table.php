<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gespeicherte Auswertungen (Report-Engine) und ihre Rechte. Die Definition ist JSON mit
 * Verweisen auf Tabellen- und Feld-IDs, nie SQL. Kein Benutzer erhält die Rechte automatisch;
 * super-admin hat sie implizit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('definition');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
            $table->index(['is_shared', 'owner_id']);
        });

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::findOrCreate("reports.{$action}", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Permission::query()->where('name', 'like', 'reports.%')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
