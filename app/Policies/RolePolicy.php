<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksResourcePermissions;
use Illuminate\Database\Eloquent\Model;

class RolePolicy
{
    use ChecksResourcePermissions;

    protected function permissionPrefix(): string
    {
        return 'roles';
    }

    /** Die Super-Admin-Rolle ist systemkritisch und unveränderlich. */
    public function update(User $user, Model $record): bool
    {
        return ! $this->isProtected($record) && $this->hasResourcePermission($user, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return ! $this->isProtected($record) && $this->hasResourcePermission($user, 'delete');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->delete($user, $record);
    }

    private function isProtected(Model $record): bool
    {
        return $record->getAttribute('name') === User::ROLE_SUPER_ADMIN;
    }
}
