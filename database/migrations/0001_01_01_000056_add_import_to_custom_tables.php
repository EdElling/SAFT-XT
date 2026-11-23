<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Datenimport in eine bestehende Tabelle (analog zum Export): Schalter in den Stammdaten (Vorgabe aus) und Recht
 * data.<tabelle>.import. Bestehende Rollen erhalten das Recht nicht, der Import muss bewusst freigegeben werden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->boolean('import_enabled')->default(false)->after('export_enabled');
        });

        foreach (DB::table('custom_tables')->where('source', '<>', 'external')->pluck('name') as $name) {
            Permission::findOrCreate("data.{$name}.import", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', 'like', 'data.%.import')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropColumn('import_enabled');
        });
    }
};
