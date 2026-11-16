<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Verknüpfungen nur für Auswertungen (Etappe 3b): Feld einer Quelltabelle → eindeutiges Feld einer
 * Zieltabelle, auch zwischen SAFT-Tabellen und angebundenen Tabellen verschiedener Verbindungen.
 * target_field_id leer = Spalte id einer SAFT-Tabelle. Dazu Rechte report_links.* und Menüeintrag.
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.resources.report-links.index';

    public function up(): void
    {
        Schema::create('report_links', function (Blueprint $table): void {
            $table->id();
            $table->json('label');
            $table->foreignId('source_table_id')->constrained('custom_tables')->cascadeOnDelete();
            $table->foreignId('source_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->foreignId('target_table_id')->constrained('custom_tables')->cascadeOnDelete();
            $table->foreignId('target_field_id')->nullable()->constrained('custom_fields')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['source_field_id', 'target_table_id']);
        });

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::findOrCreate("report_links.{$action}", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');

        if ($admin === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => $admin,
            'label' => json_encode(['de' => 'Verknüpfungen für Auswertungen', 'en' => 'Report links', 'fr' => 'Liaisons pour rapports', 'it' => 'Collegamenti per report'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-arrows-right-left',
            'permission_name' => 'report_links.view',
            'sort_order' => 73,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->where('guard_name', 'web')->where('name', 'like', 'report\_links.%')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('report_links');
    }
};
