<?php

declare(strict_types=1);

use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Hauptpunkt „Auswertungen“ für bestehende Installationen (neue legt der MenuSeeder an). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route', MenuSeeder::REPORTS_ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => null,
            'label' => json_encode(MenuSeeder::REPORTS_LABEL, JSON_UNESCAPED_UNICODE),
            'route' => MenuSeeder::REPORTS_ROUTE,
            'icon' => 'heroicon-o-presentation-chart-bar',
            'permission_name' => 'reports.view',
            'sort_order' => 800,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', MenuSeeder::REPORTS_ROUTE)->delete();
    }
};
