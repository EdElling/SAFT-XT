<?php

declare(strict_types=1);

namespace App\DynamicData\Diagram;

use App\Models\CustomField;
use App\Models\CustomTable;

/**
 * Tabellen einer Menügruppe und ihre Verknüpfungen, gefiltert nach den Rechten eines Benutzers.
 * Randknoten sind verknüpfte Tabellen außerhalb der Gruppe (nur mit Namen dargestellt).
 */
final readonly class DataModelGraph
{
    /**
     * @param  array<string, CustomTable>  $tables  Tabellen der Gruppe, nach Name
     * @param  array<string, CustomTable>  $outside  Randknoten, nach Name
     * @param  list<array{from: string, to: string, field: CustomField, required: bool, detail: bool, link: ?string}>  $edges
     */
    public function __construct(
        public array $tables,
        public array $outside,
        public array $edges,
    ) {}

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }
}
