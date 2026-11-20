<?php

declare(strict_types=1);

namespace App\Filament\Resources\IdentityProviders\Pages;

use App\Filament\Concerns\HeaderActionsInTable;
use App\Filament\Concerns\RemembersTableColumns;
use App\Filament\Resources\IdentityProviders\IdentityProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIdentityProviders extends ListRecords
{
    use HeaderActionsInTable;
    use RemembersTableColumns;

    protected static string $resource = IdentityProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
