<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Concerns\DetectsConcurrentEdits;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Schemas\MenuForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMenu extends EditRecord
{
    use DetectsConcurrentEdits;
    use LimitsFormWidth;

    protected static string $resource = MenuResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return MenuForm::fill($this->getRecord(), $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return MenuForm::normalize($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('menus.delete_children')),
        ];
    }
}
