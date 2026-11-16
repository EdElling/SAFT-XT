<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\Pages;

use App\ExternalData\TableConnectorService;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\TableConnector\TableConnectorResource;
use App\Models\DatabaseConnection;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateConnectedTable extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = TableConnectorResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(TableConnectorService::class)->connect(
            DatabaseConnection::query()->findOrFail((int) $data['database_connection_id']),
            (string) $data['external_schema'],
            (string) $data['external_table'],
            $data,
        );
    }

    protected function getRedirectUrl(): string
    {
        // Nach dem Anbinden direkt zu den Spalten.
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
