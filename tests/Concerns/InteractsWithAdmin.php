<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

trait InteractsWithAdmin
{
    protected function seedAdminStructure(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, MenuSeeder::class]);
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
