<?php

declare(strict_types=1);

namespace App\Filament\Resources\IdentityProviders\Pages;

use App\Auth\Sso\Drivers\SamlDriver;
use App\Auth\Sso\IdentityProviderType;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\IdentityProviders\Actions\TestConnectionAction;
use App\Filament\Resources\IdentityProviders\IdentityProviderResource;
use App\Filament\Resources\IdentityProviders\Schemas\IdentityProviderForm;
use App\Models\IdentityProvider;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditIdentityProvider extends EditRecord
{
    use LimitsFormWidth;

    protected static string $resource = IdentityProviderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var IdentityProvider $record */
        $record = $this->getRecord();

        return IdentityProviderForm::applySecrets($data, $record);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Vorher speichern, damit genau die angezeigte Konfiguration getestet wird.
            TestConnectionAction::make(function (): void {
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
            })->after(fn () => $this->refreshFormData(['last_tested_at', 'last_test_successful', 'last_test_message'])),
            Action::make('refreshSamlMetadata')
                ->label(__('idp.saml.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (IdentityProvider $record): bool => $record->type === IdentityProviderType::Saml && filled($record->setting('idp_metadata_url')))
                ->authorize(fn (IdentityProvider $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->action(function (IdentityProvider $record): void {
                    app(SamlDriver::class)->clearMetadataCache($record);
                    Notification::make()->title(__('auth_admin.saml.metadata_refreshed'))->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
