<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Plugins\PluginPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** @var array<string, list<string>> Ressource => Aktionen */
    private const array PERMISSIONS = [
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'create', 'update', 'delete'],
        'menus' => ['view', 'create', 'update', 'delete'],
        'activity_log' => ['view'],
        'identity_providers' => ['view', 'create', 'update', 'delete'],
        'settings.auth' => ['view', 'update'],
        'settings.app' => ['view', 'update'],
        'custom_tables' => ['view', 'create', 'update', 'delete', 'import', 'assistant'],
        'database_connections' => ['view', 'create', 'update', 'delete'],
        'table_connector' => ['view', 'create', 'update', 'delete'],
        'reports' => ['view', 'create', 'update', 'delete', 'assistant'],
        'report_links' => ['view', 'create', 'update', 'delete'],
        'tasks' => ['view', 'create', 'update', 'delete', 'details'],
        'plugins' => ['view', 'manage'],
        'apps' => ['view', 'manage', 'export', 'install'],
        'documents' => ['manage', 'generate'],
        'help' => ['assistant'],
        'system_log' => ['view'],
        'widgets' => [...self::PERSONAL_WIDGETS, 'recent_changes', 'idp_status', 'login_activity', 'users_overview', 'tasks'],
    ];

    /** Widgets mit ausschließlich eigenen Daten – standardmäßig für die Rolle "benutzer". */
    public const array PERSONAL_WIDGETS = ['last_login', 'my_logins', 'my_changes', 'account_changes', 'reports'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $created = [];

        foreach (self::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $permission = Permission::firstOrCreate(['name' => "{$resource}.{$action}", 'guard_name' => 'web']);

                if ($permission->wasRecentlyCreated) {
                    $created[] = $permission->name;
                }
            }
        }

        // Super-Admin erhält Rechte implizit über Gate::after (AppServiceProvider).
        Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web');

        // Standardrolle für normale Benutzer: bewusst ohne Admin-Permissions.
        // Fachmodule vergeben ihr später eigene Rechte.
        $default = Role::findOrCreate(User::ROLE_DEFAULT, 'web');

        // Persönliche Widgets nur beim erstmaligen Anlegen (der Rolle bzw. des Rechts) zuweisen, damit
        // spätere Anpassungen im Rollen-Dialog bei erneutem Seeding erhalten bleiben. Neue Rolle: alle –
        // bei einer Neuinstallation haben Migrationen manche Widget-Rechte schon vor dem Seeder angelegt.
        $personal = array_map(static fn (string $w): string => "widgets.{$w}", self::PERSONAL_WIDGETS);
        $newPersonal = $default->wasRecentlyCreated ? $personal : array_values(array_intersect($created, $personal));

        if ($newPersonal !== []) {
            $default->givePermissionTo($newPersonal);
        }

        // Rechte installierter Plugins (sonst nur nach „migrate“ angelegt).
        app(PluginPermissions::class)->sync();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
