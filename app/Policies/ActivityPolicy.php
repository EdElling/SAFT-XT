<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Das Änderungsprotokoll ist revisionssicher: nur lesend, auch für Super-Admins.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activity_log.view');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can('activity_log.view');
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
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
