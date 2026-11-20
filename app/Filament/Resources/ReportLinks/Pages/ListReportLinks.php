<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportLinks\Pages;

use App\Filament\Concerns\HasAppTabs;
use App\Filament\Concerns\HeaderActionsInTable;
use App\Filament\Resources\ReportLinks\ReportLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportLinks extends ListRecords
{
    use HasAppTabs;
    use HeaderActionsInTable;

    protected static string $resource = ReportLinkResource::class;

    public function getSubheading(): ?string
    {
        return __('report_links.description');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
