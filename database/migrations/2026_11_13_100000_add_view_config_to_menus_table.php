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
            // Ansicht eines Menüeintrags vom Typ "Datentabelle", z. B. {"columns": [...]}.
            $table->json('view_config')->nullable()->after('route_parameters');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->dropColumn('view_config');
        });
    }
};
