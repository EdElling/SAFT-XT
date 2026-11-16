<?php

declare(strict_types=1);

namespace App\Filament\Resources\IdentityProviders\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\IdentityProviders\IdentityProviderResource;
use App\Filament\Resources\IdentityProviders\Schemas\IdentityProviderForm;
use Filament\Resources\Pages\CreateRecord;

class CreateIdentityProvider extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = IdentityProviderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return IdentityProviderForm::applySecrets($data, null);
    }

    protected function getRedirectUrl(): string
    {
        // Nach dem Anlegen direkt in den Dialog, um die Verbindung zu testen.
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
