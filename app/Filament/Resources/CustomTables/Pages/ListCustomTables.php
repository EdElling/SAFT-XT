<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\Pages;

use App\Filament\Concerns\RemembersTableColumns;
use App\Filament\Resources\CustomTables\CustomTableResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomTables extends ListRecords
{
    use RemembersTableColumns;

    protected static string $resource = CustomTableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(__('import.wizard.action'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(fn (): string => ImportCustomTable::getUrl())
                ->visible(fn (): bool => ImportCustomTable::canAccess()),
            CreateAction::make(),
        ];
    }
}
