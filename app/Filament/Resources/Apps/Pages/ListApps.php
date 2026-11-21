<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Concerns\HeaderActionsInTable;
use App\Filament\Pages\AppDesignerPage;
use App\Filament\Resources\Apps\Actions\InstallAppAction;
use App\Filament\Resources\Apps\AppResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

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
        return [
            // KI-App-Designer (B-107, Etappe 7a).
            Action::make('designApp')
                ->label(__('app_designer.action'))
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn (): bool => auth()->user()?->can('apps.design') ?? false)
                ->url(AppDesignerPage::getUrl()),
            InstallAppAction::make(),
            CreateAction::make(),
        ];
    }
}
