<?php

declare(strict_types=1);

namespace App\Apps;

use App\Models\Role;
use App\Models\User;

/**
 * Rechte, die je App gelten (B-107, Etappe 2) – derzeit die Rechte der Auswertungen: Eine Person hat ein Recht in einer
 * App, wenn eine ihrer Rollen dieser App oder eine Systemrolle es gibt (oder es ihr direkt gegeben ist). Der
 * Super-Admin hat alle Rechte in allen Apps. Bei „Ansicht als Rolle“ zählen die simulierten Rollen.
 */
final class AppPermissions
{
    public static function can(User $user, string $permission, ?int $appId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->getDirectPermissions()->contains('name', $permission)) {
            return true;
        }

        return $user->roles
            ->filter(static fn (Role $role): bool => $role->app_id === null || $role->app_id === $appId)
            ->contains(static fn (Role $role): bool => $role->hasPermissionTo($permission));
    }

    /** In der aktuellen App. */
    public static function current(User $user, string $permission): bool
    {
        return self::can($user, $permission, app(CurrentApp::class)->id($user));
    }
}
