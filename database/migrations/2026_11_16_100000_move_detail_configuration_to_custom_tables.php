<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master-Detail-Kaskade: Details sind eine Eigenschaft der Tabelle (gleich, über welches
 * Menü man einsteigt), nicht mehr des Menüeintrags. Bestehende Einstellungen der
 * Menüeinträge werden je Tabelle zusammengeführt.
 */
return new class extends Migration
{
    private const string DATA_ROUTE = 'filament.admin.pages.data';

    public function up(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            // Feld-IDs der Verknüpfungsfelder in Kindtabellen (1:n), Reihenfolge = Anzeige.
            $table->json('detail_field_ids')->nullable()->after('is_active');
        });

        $byTable = [];

        foreach (DB::table('menus')->where('route', self::DATA_ROUTE)->whereNotNull('view_config')->get(['id', 'route_parameters', 'view_config']) as $menu) {
            $table = json_decode((string) $menu->route_parameters, true)['table'] ?? null;
            $config = (array) json_decode((string) $menu->view_config, true);

            if (is_string($table) && ! empty($config['details'])) {
                $byTable[$table] = array_values(array_unique([...($byTable[$table] ?? []), ...array_map('intval', (array) $config['details'])]));
            }

            unset($config['details']);
            DB::table('menus')->where('id', $menu->id)->update(['view_config' => $config === [] ? null : json_encode($config)]);
        }

        foreach ($byTable as $table => $ids) {
            DB::table('custom_tables')->where('name', $table)->update(['detail_field_ids' => json_encode($ids)]);
        }
    }

    public function down(): void
    {
        Schema::table('custom_tables', function (Blueprint $table): void {
            $table->dropColumn('detail_field_ids');
        });
    }
};
