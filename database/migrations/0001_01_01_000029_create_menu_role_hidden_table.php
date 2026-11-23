<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menüeinträge, die eine Rolle ausblendet. Gespeichert wird nur das Ausblenden, damit neue
 * Einträge und neue Rollen ohne Zutun sichtbar sind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_role_hidden', function (Blueprint $table): void {
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained(config('permission.table_names.roles', 'roles'))->cascadeOnDelete();
            $table->primary(['menu_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_role_hidden');
    }
};
