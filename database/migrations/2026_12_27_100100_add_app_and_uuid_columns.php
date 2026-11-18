<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * B-107: Zuordnung zu einer App (app_id) und stabile Schlüssel (uuid) für die fachlichen Objekte. app_id ist leer bei
 * Systemobjekten (Systemrollen, System-Menüs, System-Aufgaben); eine App lässt sich nicht löschen, solange etwas an
 * ihr hängt. Vorhandene Einträge erhalten hier ihren Schlüssel.
 */
return new class extends Migration
{
    /** Tabellen mit App-Zuordnung. */
    private const array WITH_APP = ['custom_tables', 'menus', 'reports', 'report_links', 'roles', 'scheduled_tasks'];

    /** Tabellen mit stabilem Schlüssel. */
    private const array WITH_UUID = ['custom_tables', 'custom_fields', 'menus', 'reports', 'report_links', 'roles', 'scheduled_tasks'];

    public function up(): void
    {
        foreach (self::WITH_UUID as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->uuid('uuid')->nullable()->after('id');

                if (in_array($name, self::WITH_APP, true)) {
                    $table->foreignId('app_id')->nullable()->after('uuid')->constrained('apps')->restrictOnDelete();
                }
            });

            DB::table($name)->whereNull('uuid')->orderBy('id')->select('id')->chunkById(500, static function ($rows) use ($name): void {
                foreach ($rows as $row) {
                    DB::table($name)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
                }
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::WITH_UUID as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique(['uuid']);

                if (in_array($name, self::WITH_APP, true)) {
                    $table->dropConstrainedForeignId('app_id');
                }

                $table->dropColumn('uuid');
            });
        }
    }
};
