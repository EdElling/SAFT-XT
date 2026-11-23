<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Concerns\DetectsConcurrentEdits;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Apps\AppResource;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Models\App;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditApp extends EditRecord
{
    use DetectsConcurrentEdits;
    use LimitsFormWidth;

    protected static string $resource = AppResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->disabled(fn (App $record): bool => ! $record->isEmpty())
                ->tooltip(fn (App $record): ?string => $record->isEmpty() ? null : __('apps.delete_only_empty')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var App $app */
        $app = $this->getRecord();

        return AppForm::fill($app, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AppForm::normalize($data);
    }
}
