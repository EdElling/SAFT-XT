<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\Pages;

use App\Filament\Concerns\HasAppTabs;
use App\Filament\Concerns\HeaderActionsInTable;
use App\Filament\Concerns\RemembersTableColumns;
use App\Filament\Resources\TableConnector\TableConnectorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConnectedTables extends ListRecords
{
    use HasAppTabs;
    use HeaderActionsInTable;
    use RemembersTableColumns;

    protected static string $resource = TableConnectorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('table_connector.actions.connect')),
        ];
    }
}
