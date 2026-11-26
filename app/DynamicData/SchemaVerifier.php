<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\DynamicData\Schema\IdentifierValidator;
use App\ExternalData\Snapshots\SnapshotService;
use App\Models\CustomTable;

/**
 * Vergleicht Metadaten (custom_tables/custom_fields) mit dem physischen Namensraum.
 */
final class SchemaVerifier
{
    public function __construct(private readonly DynamicDataNamespace $namespace) {}

    /**
     * @return list<string> gefundene Abweichungen (leer = konsistent)
     */
    public function issues(): array
    {
        $schema = $this->namespace->connection()->getSchemaBuilder();
        $physical = array_column($schema->getTables($this->namespace->name()), 'name');
        $issues = [];

        $tables = CustomTable::query()->designer()->with('fields')->orderBy('name')->get();

        foreach ($tables as $table) {
            if (! in_array($table->name, $physical, true)) {
                $issues[] = __('tables.verify.missing_table', ['table' => $table->name]);

                continue;
            }

            $columns = $schema->getColumnListing($this->namespace->qualify($table->name));
            $expected = [...IdentifierValidator::SYSTEM_COLUMNS, ...$table->fields->pluck('name')->all()];

            // Hilfsspalten des Dialekts (MariaDB: eindeutige Werte) gehören zu eindeutigen Feldern.
            $dialect = $this->namespace->dialect();
            $helpers = $table->fields->where('is_unique', true)
                ->map(fn ($field): string => $field->indexName('uq').'_v')
                ->filter(fn (string $column): bool => $dialect->isHelperColumn($column))
                ->values()
                ->all();
            // Übrig gebliebene Hilfsspalten erscheinen unten als überzählig.
            $expected = [...$expected, ...$helpers];

            foreach (array_diff($expected, $columns) as $missing) {
                $issues[] = __('tables.verify.missing_column', ['table' => $table->name, 'column' => $missing]);
            }

            foreach (array_diff($columns, $expected) as $extra) {
                $issues[] = __('tables.verify.extra_column', ['table' => $table->name, 'column' => $extra]);
            }
        }

        // Kopien der Datenstände (ds_…) gehören zu angebundenen Tabellen, saft_… sind Systemtabellen (Zähler der
        // Nummernkreise), beide nicht zum Table-Designer.
        $physical = array_filter($physical, fn (string $name): bool => ! str_starts_with($name, SnapshotService::PREFIX) && ! str_starts_with($name, 'saft_'));

        foreach (array_diff($physical, $tables->pluck('name')->all()) as $orphan) {
            $issues[] = __('tables.verify.orphan_table', ['table' => $orphan]);
        }

        return $issues;
    }
}
