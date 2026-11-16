<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Persönliche Auswahl „Auf mein Dashboard“ (Auswertungen je Person) und das Widget-Recht
 * „Meine Auswertungen“. Die Standardrolle erhält es wie die übrigen persönlichen Widgets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'report_id']);
        });

        $permission = Permission::findOrCreate('widgets.reports', 'web');
        Role::query()->where('name', User::ROLE_DEFAULT)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_reports');
        Permission::query()->where('name', 'widgets.reports')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
