<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Pages;

use App\Filament\Concerns\RemembersTableColumns;
use App\Filament\Resources\DatabaseConnections\DatabaseConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDatabaseConnections extends ListRecords
{
    use RemembersTableColumns;

    protected static string $resource = DatabaseConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
