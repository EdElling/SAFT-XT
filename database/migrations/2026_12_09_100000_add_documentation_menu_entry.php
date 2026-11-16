<?php

declare(strict_types=1);

use Database\Seeders\MenuSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Menüeintrag „Dokumentation“ (Online-Hilfe) für bestehende Installationen, direkt unter dem Dashboard. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('route', MenuSeeder::DOCUMENTATION_ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => null,
            'label' => json_encode(MenuSeeder::DOCUMENTATION_LABEL, JSON_UNESCAPED_UNICODE),
            'route' => MenuSeeder::DOCUMENTATION_ROUTE,
            'icon' => 'heroicon-o-book-open',
            'permission_name' => null,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', MenuSeeder::DOCUMENTATION_ROUTE)->delete();
    }
};
