<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\HasAppTabs;
use App\Filament\Concerns\HeaderActionsInTable;
use App\Filament\Concerns\RemembersTableColumns;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    use HasAppTabs;
    use HeaderActionsInTable;
    use RemembersTableColumns;

    protected function appTabsWithSystem(): bool
    {
        return true;
    }

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
