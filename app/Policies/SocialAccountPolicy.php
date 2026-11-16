<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * SSO-Verknüpfungen entstehen nur durch Anmeldung; Admins dürfen sie einsehen und trennen.
 */
class SocialAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->can('users.update');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('users.update');
    }
}
