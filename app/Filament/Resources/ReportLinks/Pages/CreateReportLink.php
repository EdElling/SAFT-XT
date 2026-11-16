<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportLinks\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\ReportLinks\ReportLinkResource;
use App\Filament\Resources\ReportLinks\Schemas\ReportLinkForm;
use Filament\Resources\Pages\CreateRecord;

class CreateReportLink extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = ReportLinkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ReportLinkForm::toRecord($data);
    }
}
