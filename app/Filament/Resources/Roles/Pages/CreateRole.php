<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\AuditsRelationChanges;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends CreateRecord
{
    use AuditsRelationChanges;
    use LimitsFormWidth;

    protected static string $resource = RoleResource::class;

    protected function auditedRelations(): array
    {
        return ['permissions' => 'name', 'hiddenMenus' => 'menus.label'];
    }

    protected function afterRelationsAudited(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
