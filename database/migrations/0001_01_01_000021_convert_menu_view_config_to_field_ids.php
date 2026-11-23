<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ansichten (menus.view_config) referenzieren Felder künftig per ID statt Name:
 * Umbenennen im Table-Designer lässt Spaltenauswahl und Details unverändert.
 */
return new class extends Migration
{
    private const string DATA_ROUTE = 'filament.admin.pages.data';

    public function up(): void
    {
        $this->convert(fn (array $byName, mixed $value): mixed => is_string($value) ? ($byName[$value] ?? null) : $value);
    }

    public function down(): void
    {
        $this->convert(fn (array $byName, mixed $value): mixed => is_int($value) ? (array_flip($byName)[$value] ?? null) : $value);
    }

    private function convert(Closure $map): void
    {
        $menus = DB::table('menus')->where('route', self::DATA_ROUTE)->whereNotNull('view_config')->get(['id', 'route_parameters', 'view_config']);

        foreach ($menus as $menu) {
            $table = json_decode((string) $menu->route_parameters, true)['table'] ?? null;
            $config = json_decode((string) $menu->view_config, true);

            if (! is_string($table) || ! is_array($config)) {
                continue;
            }

            $byName = DB::table('custom_fields')
                ->join('custom_tables', 'custom_tables.id', '=', 'custom_fields.custom_table_id')
                ->where('custom_tables.name', $table)
                ->pluck('custom_fields.id', 'custom_fields.name')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $config['columns'] = array_values(array_filter(
                array_map(fn (mixed $value): mixed => $map($byName, $value), (array) ($config['columns'] ?? [])),
                fn (mixed $value): bool => $value !== null,
            ));

            DB::table('menus')->where('id', $menu->id)->update(['view_config' => json_encode($config)]);
        }
    }
};
