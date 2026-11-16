<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Auth\TwoFactor;
use App\Filament\Concerns\AuditsRelationChanges;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use AuditsRelationChanges;
    use LimitsFormWidth;

    protected static string $resource = UserResource::class;

    protected function auditedRelations(): array
    {
        return ['roles' => 'name'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetTwoFactor')
                ->label(__('users.two_factor.reset'))
                ->icon('heroicon-o-device-phone-mobile')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('users.two_factor.reset_confirm'))
                ->visible(fn (User $record): bool => app(TwoFactor::class)->isSetUp($record) && (auth()->user()?->can('update', $record) ?? false))
                ->action(function (User $record): void {
                    app(TwoFactor::class)->reset($record, ($user = auth()->user()) instanceof User ? $user : null);
                    Notification::make()->success()->title(__('users.two_factor.reset_done'))->send();
                }),
            DeleteAction::make()->modalDescription(fn (): string => __('users.delete.soft')),
            ForceDeleteAction::make()->modalDescription(fn (): string => __('users.delete.force')),
            RestoreAction::make(),
        ];
    }
}
