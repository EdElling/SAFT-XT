<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkItem;

/**
 * Arbeiten (Fundament 2, F3a): Eigene sieht und bricht jede Person ab; alle sehen mit work.view_any, fremde abbrechen
 * mit work.cancel_any. Angelegt werden Arbeiten nur von SAFT selbst, geändert nie von Hand.
 */
class WorkItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkItem $item): bool
    {
        return $item->user_id === $user->id || $user->can('work.view_any');
    }

    public function cancel(User $user, WorkItem $item): bool
    {
        return $item->isOpen() && ($item->user_id === $user->id || $user->can('work.cancel_any'));
    }

    public function details(User $user, WorkItem $item): bool
    {
        return $item->error_detail !== null && $this->view($user, $item) && $user->can('work.details');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WorkItem $item): bool
    {
        return false;
    }

    public function delete(User $user, WorkItem $item): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
