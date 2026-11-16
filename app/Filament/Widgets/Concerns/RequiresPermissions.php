<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Models\User;

/**
 * Sichtbarkeit eines Dashboard-Widgets über Permissions: die Widget-Permission
 * ("widgets.<key>") und ggf. die Permission der angezeigten Daten (alle nötig).
 * Filament erzwingt canView() auch beim (Lazy-)Laden des Widgets (403).
 */
trait RequiresPermissions
{
    /**
     * @return list<string>
     */
    abstract protected static function requiredPermissions(): array;

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        foreach (static::requiredPermissions() as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
