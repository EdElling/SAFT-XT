<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksResourcePermissions;
use Illuminate\Database\Eloquent\Model;

class UserPolicy
{
    use ChecksResourcePermissions;

    protected function permissionPrefix(): string
    {
        return 'users';
    }

    public function update(User $user, Model $record): bool
    {
        return $this->canManage($user, $record)
            && $this->hasResourcePermission($user, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return ! $record->is($user)
            && $this->canManage($user, $record)
            && $this->hasResourcePermission($user, 'delete');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->canManage($user, $record)
            && $this->hasResourcePermission($user, 'delete');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->delete($user, $record);
    }

    /**
     * Super-Admin-Konten dürfen nur von Super-Admins verwaltet werden.
     */
    private function canManage(User $user, Model $record): bool
    {
        return ! ($record instanceof User && $record->isSuperAdmin()) || $user->isSuperAdmin();
    }
}
