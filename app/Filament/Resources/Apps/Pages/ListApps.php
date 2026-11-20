<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Concerns\HeaderActionsInTable;
use App\Filament\Resources\Apps\Actions\InstallAppAction;
use App\Filament\Resources\Apps\AppResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApps extends ListRecords
{
    use HeaderActionsInTable;

    protected static string $resource = AppResource::class;

    public function getSubheading(): ?string
    {
        return __('apps.description_page');
    }

    protected function getHeaderActions(): array
    {
        return [InstallAppAction::make(), CreateAction::make()];
    }
}
