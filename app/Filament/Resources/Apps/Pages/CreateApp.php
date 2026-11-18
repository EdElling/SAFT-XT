<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Apps\AppResource;
use App\Filament\Resources\Apps\Schemas\AppForm;
use Filament\Resources\Pages\CreateRecord;

class CreateApp extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = AppResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AppForm::normalize($data);
    }
}
