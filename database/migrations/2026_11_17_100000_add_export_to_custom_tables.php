<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Export je Tabelle: Schalter in den Stammdaten (Vorgabe aus) und Recht data.<tabelle>.export.
 * Bestehende Rollen erhalten das Recht nicht, der Export muss bewusst freigegeben werden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->boolean('export_enabled')->default(false)->after('is_active');
        });

        foreach (DB::table('custom_tables')->pluck('name') as $name) {
            Permission::findOrCreate("data.{$name}.export", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', 'like', 'data.%.export')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropColumn('export_enabled');
        });
    }
};
