<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportLinks\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\ReportLinks\ReportLinkResource;
use App\Filament\Resources\ReportLinks\Schemas\ReportLinkForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReportLink extends EditRecord
{
    use LimitsFormWidth;

    protected static string $resource = ReportLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ReportLinkForm::toForm($data, $this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ReportLinkForm::toRecord($data);
    }
}
