<?php

declare(strict_types=1);

namespace App\Apps;

use App\Models\User;
use App\Settings\AuthSettings;
use Database\Seeders\MenuSeeder;
use Throwable;

/**
 * Was zu keiner App gehört (B-107): Systemrollen und Menüeinträge, die nur Seiten des Systems öffnen (Dashboard,
 * Dokumentation, Auswertungen, Administration). Alles andere gehört zu genau einer App.
 */
final class SystemObjects
{
    /** @return list<string> Routen der System-Menüs */
    public static function routes(): array
    {
        $routes = ['filament.admin.pages.dashboard', MenuSeeder::DOCUMENTATION_ROUTE, MenuSeeder::REPORTS_ROUTE];

        foreach (MenuSeeder::ADMIN_SUBMENUS as $submenu) {
            foreach ($submenu['children'] as $entry) {
                $routes[] = (string) $entry['route'];
            }
        }

        return array_values(array_unique($routes));
    }

    public static function isSystemRoute(?string $route): bool
    {
        return $route !== null && $route !== '' && in_array($route, self::routes(), true);
    }

    /** @return list<string> Namen der Systemrollen (Super-Admin, Standardrolle, ggf. eingestellte Standardrolle) */
    public static function roles(): array
    {
        $roles = [User::ROLE_SUPER_ADMIN, User::ROLE_DEFAULT];

        try {
            $configured = app(AuthSettings::class)->default_role;
        } catch (Throwable) {
            $configured = null;
        }

        return array_values(array_unique(array_filter([...$roles, $configured])));
    }

    public static function isSystemRole(?string $name): bool
    {
        return $name !== null && in_array($name, self::roles(), true);
    }
}
