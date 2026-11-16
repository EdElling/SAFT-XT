<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportLinks\Pages;

use App\Filament\Resources\ReportLinks\ReportLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportLinks extends ListRecords
{
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
