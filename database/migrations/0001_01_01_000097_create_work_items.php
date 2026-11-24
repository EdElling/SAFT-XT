<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arbeiten (Fundament 2, F3a): lange Arbeiten in wiederaufnehmbaren Schritten, vorangetrieben vom Browser der
 * startenden Person oder vom Scheduler (work:run). Siehe docs/concept/FOUNDATION_2.md §6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Art (WorkType::key()), z. B. recompute.
            $table->string('type', 100);
            $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
            // Startende Person; leer bei Arbeiten des Systems (Konsole, Aufgaben).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Rollen-IDs, wenn unter „Ansicht als Rolle“ gestartet: gelten auch im Hintergrund.
            $table->json('simulated_roles')->nullable();
            $table->json('parameters')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedBigInteger('total')->nullable();
            $table->unsignedBigInteger('done')->default(0);
            // Cursor für die Wiederaufnahme.
            $table->json('state')->nullable();
            $table->json('result')->nullable();
            $table->text('message')->nullable();
            $table->json('error_detail')->nullable();
            // Begonnene Schritte ohne Abschluss in Folge (abgebrochener Prozess).
            $table->unsignedSmallInteger('stalls')->default(0);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // Ergebnisdateien bis dann; danach räumt „Arbeiten aufräumen“ sie weg.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'heartbeat_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
    }
};
