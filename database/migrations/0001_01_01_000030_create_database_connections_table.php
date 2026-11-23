<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verbindungen zu externen Datenbanken (Table-Connector). Das Passwort wird verschlüsselt gespeichert.
 * Neue Verbindungen sind „nur lesen“; Schreiben muss bewusst freigeschaltet werden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('driver', 20);
            $table->string('host', 255);
            $table->unsignedInteger('port');
            $table->string('database', 128);
            $table->string('username', 128);
            $table->text('password')->nullable();
            $table->string('default_schema', 63)->nullable();
            $table->string('ssl_mode', 20)->nullable();
            $table->unsignedSmallInteger('connect_timeout')->default(5);
            $table->unsignedInteger('statement_timeout')->default(30);
            $table->boolean('is_read_only')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_successful')->nullable();
            $table->text('last_test_message')->nullable();
            // Beim letzten erfolgreichen Test gefundene Schemas, als Auswahlhilfe im Formular.
            $table->json('known_schemas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_connections');
    }
};
