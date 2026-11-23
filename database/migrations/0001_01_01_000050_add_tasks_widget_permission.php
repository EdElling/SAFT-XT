<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Dashboard-Widget „Geplante Aufgaben“ (Status der letzten Läufe) für Administratoren: Recht widgets.tasks. */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('widgets.tasks', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'widgets.tasks')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
