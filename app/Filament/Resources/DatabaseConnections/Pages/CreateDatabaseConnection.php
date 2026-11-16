<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\DatabaseConnections\DatabaseConnectionResource;
use App\Filament\Resources\DatabaseConnections\Schemas\DatabaseConnectionForm;
use Filament\Resources\Pages\CreateRecord;

class CreateDatabaseConnection extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = DatabaseConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DatabaseConnectionForm::applyPassword($data);
    }

    protected function getRedirectUrl(): string
    {
        // Nach dem Anlegen direkt in den Dialog, um die Verbindung zu testen.
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
