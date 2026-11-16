<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Geplante Aufgaben (Etappe 4): Aufgabe = Art + Parameter + Zeitplan, Lauf = eine Ausführung.
 * Dazu Rechte tasks.*, Menüeintrag „Administration › Geplante Aufgaben“ und die System-Aufgaben
 * „Datenstände bereinigen“ und „Laufprotokoll bereinigen“. Die Tabelle jobs der Laravel-Queue bleibt unberührt.
 */
return new class extends Migration
{
    private const string ROUTE = 'filament.admin.resources.scheduled-tasks.index';

    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('type', 64);
            $table->json('parameters')->nullable();
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('interval_hours')->nullable();
            $table->string('at_time', 5)->default('00:00');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('type');
        });

        Schema::create('task_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_task_id')->constrained('scheduled_tasks')->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('message')->nullable();
            $table->json('result')->nullable();
            $table->index(['scheduled_task_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::findOrCreate("tasks.{$action}", 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $now = now();
        DB::table('scheduled_tasks')->insert([
            [
                'name' => null, 'type' => 'snapshot_prune', 'parameters' => null, 'frequency' => 'daily', 'interval_hours' => null,
                'at_time' => '03:15', 'weekday' => null, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => null, 'type' => 'task_runs_prune', 'parameters' => json_encode(['days' => 90]), 'frequency' => 'daily', 'interval_hours' => null,
                'at_time' => '03:30', 'weekday' => null, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $admin = DB::table('menus')->whereNull('parent_id')->whereNull('route')->where('label->de', 'Administration')->value('id');

        if ($admin === null || DB::table('menus')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('menus')->insert([
            'parent_id' => $admin,
            'label' => json_encode(['de' => 'Geplante Aufgaben', 'en' => 'Scheduled tasks', 'fr' => 'Tâches planifiées', 'it' => 'Attività pianificate'], JSON_UNESCAPED_UNICODE),
            'route' => self::ROUTE,
            'icon' => 'heroicon-o-calendar-days',
            'permission_name' => 'tasks.view',
            'sort_order' => 78,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('route', self::ROUTE)->delete();
        Permission::query()->where('guard_name', 'web')->where('name', 'like', 'tasks.%')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('scheduled_tasks');
    }
};
