<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Felder gehören zur Struktur ihrer Tabelle: Ändern erfordert custom_tables.update. */
class CustomFieldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('custom_tables.view');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can('custom_tables.view');
    }

    public function create(User $user): bool
    {
        return $user->can('custom_tables.update');
    }

    public function update(User $user, Model $record): bool
    {
        return $user->can('custom_tables.update');
    }

    public function reorder(User $user): bool
    {
        return $user->can('custom_tables.update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->can('custom_tables.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
