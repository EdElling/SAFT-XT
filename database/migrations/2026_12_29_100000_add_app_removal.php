<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-107, Etappe 3: Entfernen einer App ist vorgemerkt und läuft frühestens nach einer Wartezeit (mind. 10 Minuten);
 * bis dahin lässt es sich abbrechen. Ein Fehler beim Ausführen bleibt sichtbar, die Vormerkung bleibt bestehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->timestamp('removal_due_at')->nullable();
            $table->foreignId('removal_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removal_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('removal_requested_by');
            $table->dropColumn(['removal_due_at', 'removal_error']);
        });
    }
};
