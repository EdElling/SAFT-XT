<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\AuditsRelationChanges;
use App\Filament\Concerns\DetectsConcurrentEdits;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Roles\Actions\CopyRoleAction;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    use AuditsRelationChanges;
    use DetectsConcurrentEdits;
    use LimitsFormWidth;

    protected static string $resource = RoleResource::class;

    protected function auditedRelations(): array
    {
        return ['permissions' => 'name', 'hiddenMenus' => 'menus.label'];
    }

    // Filament synchronisiert Permissions direkt per Relation -> Spatie-Cache manuell leeren.
    protected function afterRelationsAudited(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function getHeaderActions(): array
    {
        return [
            CopyRoleAction::make(),
            DeleteAction::make(),
        ];
    }
}
