<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Recht „Auswertungen: Assistent nutzen“ (KI-Assistent, Etappe 5). */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('reports.assistant', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'reports.assistant')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
