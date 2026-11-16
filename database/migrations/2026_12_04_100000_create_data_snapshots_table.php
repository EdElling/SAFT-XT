<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datenstände angebundener Tabellen (unveränderliche Kopien in app_data, Tabelle ds_<name>) und
 * ihre Aufbewahrung je Tabelle (letzte N Stände, optional höchstens X Tage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_table_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('row_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['custom_table_id', 'status', 'finished_at']);
        });

        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->unsignedSmallInteger('snapshot_keep')->default(5);
            $table->unsignedSmallInteger('snapshot_max_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_snapshots');
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropColumn(['snapshot_keep', 'snapshot_max_days']);
        });
    }
};
