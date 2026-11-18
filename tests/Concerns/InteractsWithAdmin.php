<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\App;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

trait InteractsWithAdmin
{
    protected function seedAdminStructure(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, MenuSeeder::class]);
        self::ensureTestApp();
    }

    /** Eine App für Tabellen, Menüs und Rollen der Tests (B-107); ohne Angabe nimmt SAFT die einzige vorhandene. */
    protected static function ensureTestApp(): App
    {
        return App::query()->orderBy('id')->first()
            ?? App::query()->create(['name' => ['de' => 'Test-App', 'en' => 'Test app'], 'prefix' => null, 'sort_order' => 1]);
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function userWithPermissions(array $permissions = []): User
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    protected function superAdmin(): User
    {
        return User::factory()->create()->assignRole(User::ROLE_SUPER_ADMIN);
    }
}
