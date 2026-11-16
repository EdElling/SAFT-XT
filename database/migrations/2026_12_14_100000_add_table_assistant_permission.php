<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Recht „Table-Designer: Assistent nutzen“ (KI-Assistent für Tabellen). */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('custom_tables.assistant', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'custom_tables.assistant')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
