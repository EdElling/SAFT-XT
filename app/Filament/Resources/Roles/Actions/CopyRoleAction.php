<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Actions;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\AppSelect;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rules\Unique;

/** Rolle als Vorlage für eine neue kopieren (B-107): Rechte und ausgeblendete Menüeinträge, ohne Mitglieder. */
final class CopyRoleAction
{
    public static function make(): Action
    {
        return Action::make('copyRole')
            ->label(__('roles.copy.action'))
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn (Role $record): bool => $record->name !== User::ROLE_SUPER_ADMIN && (auth()->user()?->can('roles.create') ?? false))
            ->modalHeading(fn (Role $record): string => __('roles.copy.heading', ['name' => $record->name]))
            ->modalDescription(__('roles.copy.description'))
            ->fillForm(fn (Role $record): array => ['name' => $record->name.'-kopie', 'app_id' => $record->app_id])
            ->schema([
                TextInput::make('name')
                    ->label(__('common.name'))
                    ->required()
                    ->maxLength(125)
                    ->alphaDash()
                    ->unique('roles', 'name', modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('guard_name', 'web')),
                AppSelect::make(),
            ])
            ->action(function (Role $record, array $data, Action $action): void {
                $copy = $record->copyAs((string) $data['name'], (int) $data['app_id']);
                Notification::make()->success()->title(__('roles.copy.done', ['name' => $copy->name]))->send();
                $action->redirect(RoleResource::getUrl('edit', ['record' => $copy]));
            });
    }
}
