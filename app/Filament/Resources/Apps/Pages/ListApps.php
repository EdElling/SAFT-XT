<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Resources\Apps\AppResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApps extends ListRecords
{
    protected static string $resource = AppResource::class;

    public function getSubheading(): ?string
    {
        return __('apps.description_page');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
