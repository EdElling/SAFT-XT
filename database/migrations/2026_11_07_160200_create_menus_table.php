<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->cascadeOnDelete();
            $table->string('label');
            // Benannte Laravel-Route ODER externe URL; Gruppen-Knoten haben keins von beiden.
            $table->string('route')->nullable();
            $table->string('url')->nullable();
            $table->string('icon', 100)->nullable();
            // Null = für jeden authentifizierten User sichtbar.
            $table->string('permission_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
            $table->index('permission_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
