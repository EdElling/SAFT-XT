<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Recht „Hilfe: Assistent nutzen“ (help.assistant); Rollen erhalten es in der Rollenverwaltung. */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('help.assistant', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'help.assistant')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
