<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Schemas\MenuForm;
use Filament\Resources\Pages\CreateRecord;

class CreateMenu extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = MenuResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return MenuForm::normalize($data);
    }
}
