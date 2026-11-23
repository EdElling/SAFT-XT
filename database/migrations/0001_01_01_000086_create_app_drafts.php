<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B-107, Etappe 7a: Entwürfe des KI-App-Designers (je Person mehrere) und das Recht apps.design. Wer Apps installieren
 * darf, darf sie auch mit KI entwerfen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            // Gesprächsverlauf mit der KI und der letzte gültige Vorschlag (Antwort der KI) samt Definition saft-app/1.
            $table->json('history')->nullable();
            $table->json('proposal')->nullable();
            $table->json('definition')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        $design = Permission::findOrCreate('apps.design', 'web');
        $install = DB::table('permissions')->where('name', 'apps.install')->where('guard_name', 'web')->value('id');

        if ($install !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $install)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $design->id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('app_drafts');
        Permission::query()->where('name', 'apps.design')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
