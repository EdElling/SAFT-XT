<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Bildet die Policy-Abilities von Filament auf Permissions im Schema
 * "{prefix}.{view|create|update|delete}" ab.
 *
 * Super-Admins erhalten Zugriff über Gate::after (AppServiceProvider); eine
 * Policy, die explizit false liefert, gilt daher auch für Super-Admins.
 */
trait ChecksResourcePermissions
{
    abstract protected function permissionPrefix(): string;

    protected function hasResourcePermission(User $user, string $action): bool
    {
        return $user->can("{$this->permissionPrefix()}.{$action}");
    }

    public function viewAny(User $user): bool
    {
        return $this->hasResourcePermission($user, 'view');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->hasResourcePermission($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->hasResourcePermission($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->hasResourcePermission($user, 'update');
    }

    public function reorder(User $user): bool
    {
        return $this->hasResourcePermission($user, 'update');
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->hasResourcePermission($user, 'create');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->hasResourcePermission($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->hasResourcePermission($user, 'delete');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->hasResourcePermission($user, 'delete');
    }

    public function restoreAny(User $user): bool
    {
        return $this->hasResourcePermission($user, 'delete');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->hasResourcePermission($user, 'delete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->hasResourcePermission($user, 'delete');
    }
}
