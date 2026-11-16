<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function resources(): array
    {
        return [
            'users' => ['/admin/users', 'users.view'],
            'roles' => ['/admin/roles', 'roles.view'],
            'menus' => ['/admin/menus', 'menus.view'],
            'activity log' => ['/admin/activity-log', 'activity_log.view'],
        ];
    }

    #[DataProvider('resources')]
    public function test_resource_requires_view_permission(string $url, string $permission): void
    {
        $this->actingAs($this->userWithPermissions())->get($url)->assertForbidden();
        $this->actingAs($this->userWithPermissions([$permission]))->get($url)->assertOk();
    }

    #[DataProvider('resources')]
    public function test_super_admin_can_open_every_resource(string $url, string $permission): void
    {
        $this->actingAs($this->superAdmin())->get($url)->assertOk();
    }

    public function test_inactive_user_cannot_access_panel(): void
    {
        $user = $this->superAdmin();
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_super_admin_role_is_immutable_even_for_super_admins(): void
    {
        $admin = $this->superAdmin();
        $superRole = Role::findByName(User::ROLE_SUPER_ADMIN);
        $otherRole = Role::create(['name' => 'editor']);

        $this->assertFalse($admin->can('delete', $superRole));
        $this->assertFalse($admin->can('update', $superRole));
        $this->assertTrue($admin->can('delete', $otherRole));
    }

    public function test_nobody_can_delete_themselves(): void
    {
        $admin = $this->superAdmin();
        $manager = $this->userWithPermissions(['users.delete']);

        $this->assertFalse($admin->can('delete', $admin));
        $this->assertFalse($manager->can('delete', $manager));
        $this->assertTrue($manager->can('delete', $this->userWithPermissions()));
    }

    public function test_only_super_admins_manage_super_admin_accounts(): void
    {
        $target = $this->superAdmin();
        $manager = $this->userWithPermissions(['users.update', 'users.delete']);

        $this->assertFalse($manager->can('update', $target));
        $this->assertFalse($manager->can('delete', $target));
        $this->assertTrue($this->superAdmin()->can('update', $target));
    }

    public function test_activity_log_is_read_only_for_everyone(): void
    {
        $admin = $this->superAdmin();
        $activity = Activity::query()->firstOrFail();

        $this->assertTrue($admin->can('view', $activity));
        $this->assertFalse($admin->can('update', $activity));
        $this->assertFalse($admin->can('delete', $activity));
        $this->assertFalse($admin->can('create', Activity::class));
    }
}
