<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\AuditsRelationChanges;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
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
            DeleteAction::make()->modalDescription(fn (): string => __('users.delete.soft')),
            ForceDeleteAction::make()->modalDescription(fn (): string => __('users.delete.force')),
            RestoreAction::make(),
        ];
    }
}
