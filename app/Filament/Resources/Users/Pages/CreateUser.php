<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\AuditsRelationChanges;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use AuditsRelationChanges;
    use LimitsFormWidth;

    protected static string $resource = UserResource::class;

    protected function auditedRelations(): array
    {
        return ['roles' => 'name'];
    }
}
