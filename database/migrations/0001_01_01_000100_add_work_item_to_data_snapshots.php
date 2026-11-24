<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datenstände werden als Arbeit gezogen (Fundament 2, F3b): die Arbeit, die einen Stand gerade erzeugt, damit der
 * Reiter „Datenstände“ auf sie verweisen kann. Leer bei fertigen Ständen, deren Arbeit aufgeräumt wurde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_snapshots', function (Blueprint $table): void {
            $table->foreignId('work_item_id')->nullable()->after('created_by')->constrained('work_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_item_id');
        });
    }
};
