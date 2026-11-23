<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Pages;

use App\ExternalData\ConnectionRegistry;
use App\Filament\Concerns\DetectsConcurrentEdits;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\DatabaseConnections\Actions\DeleteDatabaseConnectionAction;
use App\Filament\Resources\DatabaseConnections\Actions\TestDatabaseConnectionAction;
use App\Filament\Resources\DatabaseConnections\DatabaseConnectionResource;
use App\Filament\Resources\DatabaseConnections\Schemas\DatabaseConnectionForm;
use App\Models\DatabaseConnection;
use Filament\Resources\Pages\EditRecord;

class EditDatabaseConnection extends EditRecord
{
    use DetectsConcurrentEdits;
    use LimitsFormWidth;

    protected static string $resource = DatabaseConnectionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DatabaseConnectionForm::applyPassword($data);
    }

    protected function afterSave(): void
    {
        /** @var DatabaseConnection $record */
        $record = $this->getRecord();
        app(ConnectionRegistry::class)->forget($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Vorher speichern, damit genau die angezeigte Konfiguration getestet wird.
            TestDatabaseConnectionAction::make(function (): void {
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
            })->after(fn () => $this->refreshFormData(['last_tested_at', 'last_test_successful', 'last_test_message', 'known_schemas'])),
            DeleteDatabaseConnectionAction::make(fn () => $this->redirect(static::getResource()::getUrl('index'))),
        ];
    }
}
