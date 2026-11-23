<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            // Parameter für Routen wie filament.admin.pages.data {table}.
            $table->json('route_parameters')->nullable()->after('route');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->dropColumn('route_parameters');
        });
    }
};
