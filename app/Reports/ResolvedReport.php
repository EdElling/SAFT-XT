<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DataSnapshot;

/** Eine gegen das Datenmodell geprüfte Auswertung, bereit für die Abfrage. */
final readonly class ResolvedReport
{
    /**
     * @param  array<string, ResolvedField>  $fields  nach Schlüssel
     * @param  list<array{alias: string, parent: string, relation: CustomField, table: CustomTable, key: string}>  $joins
     * @param  array<int, CustomTable>  $tables  alle beteiligten Tabellen (auch nur im Filter)
     * @param  array<int, DataSnapshot>  $snapshots  verwendeter Datenstand je angebundener Tabelle
     */
    public function __construct(
        public ReportDefinition $definition,
        public CustomTable $base,
        public array $fields,
        public array $joins,
        public array $tables,
        public array $snapshots = [],
    ) {}

    public function snapshot(CustomTable $table): ?DataSnapshot
    {
        return $this->snapshots[$table->id] ?? null;
    }

    public function field(string $key): ResolvedField
    {
        return $this->fields[$key];
    }
}
