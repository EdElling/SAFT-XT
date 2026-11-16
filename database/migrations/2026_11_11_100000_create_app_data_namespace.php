<?php

declare(strict_types=1);

use App\DynamicData\Ddl\DdlDialectResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Namensraum für Tabellen des Table-Designers (PostgreSQL: Schema app_data).
 * DDL über den Dialekt der Verbindung – keine dialektspezifischen Befehle hier.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection(config('dynamic_data.connection'));

        app(DdlDialectResolver::class)->for($connection)->ensureNamespace($connection, config('dynamic_data.namespace'));
    }

    public function down(): void
    {
        $connection = DB::connection(config('dynamic_data.connection'));

        app(DdlDialectResolver::class)->for($connection)->dropNamespace($connection, config('dynamic_data.namespace'));
    }
};
