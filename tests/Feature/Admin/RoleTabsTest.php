<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\Role;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Rolle bearbeiten mit Reitern (B-175): welche Reiter erscheinen. */
class RoleTabsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    /** Auch app_data in der Test-Transaktion (PostgreSQL: DDL wird mit zurückgerollt). */
    protected array $connectionsToTransact = [null, 'app_data'];

    private App $saftApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->saftApp = self::ensureTestApp();
        $this->actingAs($this->superAdmin());
    }

    private function role(?App $app = null): Role
    {
        return Role::create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => ($app ?? $this->saftApp)->id]);
    }

    private function withTable(): void
    {
        app(DataMenu::class)->ensure(app(DynamicSchemaService::class)->createTable(['name' => 'kunden', 'label' => 'Kunden'], [['name' => 'firma', 'type' => 'string']]));
    }

    public function test_app_role_shows_rights_data_rights_and_menu_entries(): void
    {
        $this->withTable();

        Livewire::test(EditRole::class, ['record' => $this->role()->getRouteKey()])
            ->assertSchemaComponentVisible('rights')
            ->assertSchemaComponentVisible('data-rights')
            ->assertSchemaComponentVisible('menu-entries')
            ->assertSchemaComponentHidden('shared-tables')
            ->assertSchemaComponentHidden('outside');
    }

    public function test_shared_tables_tab_appears_when_another_app_shares_a_table(): void
    {
        $this->withTable();
        $other = App::query()->create(['name' => ['de' => 'Lager', 'en' => 'Stock'], 'prefix' => null, 'sort_order' => 2]);
        CustomTable::query()->where('name', 'kunden')->update(['app_id' => $other->id, 'is_shared' => true]);

        Livewire::test(EditRole::class, ['record' => $this->role()->getRouteKey()])
            ->assertSchemaComponentVisible('shared-tables');
    }

    public function test_system_role_shows_only_rights(): void
    {
        $this->withTable();
        $role = Role::findByName(User::ROLE_DEFAULT, 'web');

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertSchemaComponentVisible('rights')
            ->assertSchemaComponentHidden('data-rights')
            ->assertSchemaComponentHidden('shared-tables')
            ->assertSchemaComponentHidden('menu-entries')
            ->assertSchemaComponentHidden('outside');
    }

    public function test_no_data_rights_tab_without_designer_tables(): void
    {
        Livewire::test(EditRole::class, ['record' => $this->role()->getRouteKey()])
            ->assertSchemaComponentVisible('rights')
            ->assertSchemaComponentHidden('data-rights');
    }

    public function test_outside_tab_appears_for_rights_outside_the_app_and_keeps_them_until_removed(): void
    {
        $this->withTable();
        $role = $this->role();
        $deleted = Permission::create(['name' => DataPermissions::name('geloescht', 'view'), 'guard_name' => 'web']);
        $role->givePermissionTo([$deleted, DataPermissions::name('kunden', 'view')]);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertSchemaComponentVisible('outside')
            ->call('save')
            ->assertHasNoFormErrors();

        // Speichern über alle Reiter lässt die Rechte stehen.
        $this->assertTrue($role->fresh()->hasPermissionTo($deleted));
        $this->assertTrue($role->fresh()->hasPermissionTo(DataPermissions::name('kunden', 'view')));
    }
}
