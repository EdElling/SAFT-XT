<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Recht „Table-Designer: importieren“ (Tabelle aus CSV-/Excel-Datei) für bestehende Installationen.
 * Kein Benutzer erhält es automatisch; super-admin hat es implizit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('custom_tables.import', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'custom_tables.import')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
