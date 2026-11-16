<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Models\CustomTable;
use App\Models\Menu;

/**
 * Ansicht eines Menüeintrags vom Typ "Datentabelle" (menus.view_config):
 * Spaltenauswahl als Feld-IDs (umbenennungssicher). Details: Table-Designer.
 */
final readonly class DataView
{
    /**
     * @param  list<int>|null  $columnIds  null = Designer-Vorgabe
     */
    public function __construct(
        public ?int $menuId = null,
        public ?string $label = null,
        public ?array $columnIds = null,
    ) {}

    /** Nur Menüeinträge, die wirklich zu dieser Tabelle gehören. */
    public static function forMenu(?int $menuId, string $tableName): self
    {
        $menu = $menuId === null ? null : Menu::query()
            ->whereKey($menuId)
            ->where('route', DataMenu::ROUTE)
            ->where('route_parameters->table', $tableName)
            ->first();

        if ($menu === null) {
            return new self;
        }

        $config = (array) ($menu->view_config ?? []);

        return new self(
            menuId: $menu->id,
            label: $menu->label,
            columnIds: isset($config['columns']) ? array_map('intval', (array) $config['columns']) : null,
        );
    }

    /**
     * @return list<string>|null
     */
    public function columnNames(CustomTable $table): ?array
    {
        if ($this->columnIds === null) {
            return null;
        }

        return $table->fields->whereIn('id', $this->columnIds)->pluck('name')->values()->all();
    }
}
