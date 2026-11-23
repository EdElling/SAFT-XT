<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Technische Fehlerdetails am Lauf einer geplanten Aufgabe (Referenz, Fehlerklasse, Meldung, Ort, Ablauf) und das Recht
 * „Geplante Aufgaben: technische Details sehen“ (tasks.details).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table): void {
            $table->json('error_detail')->nullable();
        });

        Permission::findOrCreate('tasks.details', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table): void {
            $table->dropColumn('error_detail');
        });

        Permission::query()->where('name', 'tasks.details')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
