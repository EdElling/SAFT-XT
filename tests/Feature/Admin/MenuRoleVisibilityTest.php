<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\Activity;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Services\DynamicSchemaService;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Menüeinträge der Datentabellen je Rolle ein- und ausblenden (nur Darstellung, additiv). */
class MenuRoleVisibilityTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    /** Auch app_data in der Test-Transaktion (PostgreSQL: DDL wird mit zurückgerollt). */
    protected array $connectionsToTransact = [null, 'app_data'];

    private Menu $customers;

    private Menu $articles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();

        $schema = app(DynamicSchemaService::class);
        $this->customers = app(DataMenu::class)->ensure($schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [['name' => 'firma', 'type' => 'string']]));
        $this->articles = app(DataMenu::class)->ensure($schema->createTable(['name' => 'artikel', 'label' => 'Artikel'], [['name' => 'name', 'type' => 'string']]));
    }

    private function role(string $name, array $hidden = []): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo([DataPermissions::name('kunden', 'view'), DataPermissions::name('artikel', 'view')]);
        $role->hiddenMenus()->sync(array_map(static fn (Menu $menu): int => $menu->id, $hidden));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    /** @return list<string> Einträge der Gruppe „Daten“ */
    private function dataEntries(User $user): array
    {
        $builder = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user->fresh());

        foreach ($builder->getNavigation() as $group) {
            /** @var NavigationGroup $group */
            if ((string) $group->getLabel() === 'Daten') {
                return collect($group->getItems())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->sort()->values()->all();
            }
        }

        return [];
    }

    public function test_entries_are_visible_by_default_and_can_be_hidden_per_role(): void
    {
        $user = User::factory()->create()->assignRole($this->role('vertrieb'));
        $this->assertSame(['Artikel', 'Kunden'], $this->dataEntries($user));

        $hiding = User::factory()->create()->assignRole($this->role('lager', [$this->articles]));
        $this->assertSame(['Kunden'], $this->dataEntries($hiding));
    }

    public function test_one_role_showing_the_entry_is_enough(): void
    {
        $user = User::factory()->create()->assignRole([$this->role('vertrieb', [$this->articles]), $this->role('lager')]);

        $this->assertSame(['Artikel', 'Kunden'], $this->dataEntries($user));
    }

    public function test_roles_without_read_permission_and_direct_permissions(): void
    {
        $hiding = $this->role('vertrieb', [$this->articles]);
        // Eine Rolle ohne Leserecht auf Artikel blendet nichts ein.
        $other = Role::create(['name' => 'gast', 'guard_name' => 'web']);
        $user = User::factory()->create()->assignRole([$hiding, $other]);
        $this->assertSame(['Kunden'], $this->dataEntries($user));

        // Ein direkt vergebenes Leserecht lässt den Eintrag sichtbar.
        $user->givePermissionTo(DataPermissions::name('artikel', 'view'));
        $this->assertSame(['Artikel', 'Kunden'], $this->dataEntries($user));
    }

    public function test_hiding_is_display_only_and_super_admin_sees_everything(): void
    {
        $user = User::factory()->create()->assignRole($this->role('vertrieb', [$this->articles]));

        $this->actingAs($user)->get('/admin/data/artikel')->assertOk();

        $admin = $this->superAdmin();
        $admin->assignRole($this->role('lager', [$this->articles, $this->customers]));
        $this->assertSame(['Artikel', 'Kunden'], $this->dataEntries($admin));
    }

    public function test_new_entries_are_visible_and_deleted_entries_leave_no_rows(): void
    {
        $role = $this->role('vertrieb', [$this->articles]);
        $user = User::factory()->create()->assignRole($role);

        $short = Menu::create([
            'label' => 'Kunden kurz', 'parent_id' => $this->customers->parent_id, 'route' => DataMenu::ROUTE,
            'route_parameters' => ['table' => 'kunden'], 'permission_name' => DataPermissions::name('kunden', 'view'),
            'sort_order' => 900, 'is_active' => true,
        ]);
        $this->assertSame(['Kunden', 'Kunden kurz'], $this->dataEntries($user));

        $this->articles->delete();
        $this->assertSame(0, $role->hiddenMenus()->count());
        $this->assertTrue($short->exists);
    }

    public function test_role_form_saves_hidden_entries_and_logs_the_change(): void
    {
        $this->actingAs($this->superAdmin());
        $role = $this->role('vertrieb');
        $id = (string) $this->articles->id;

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertFormSet(['visible_menus' => [(string) $this->customers->id, $id]])
            ->fillForm(['visible_menus' => [(string) $this->customers->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->articles->id], $role->hiddenMenus()->pluck('menus.id')->all());
        // Datenrechte bleiben unberührt.
        $this->assertTrue($role->fresh()->hasPermissionTo(DataPermissions::name('artikel', 'view')));

        $log = Activity::query()->where('subject_type', Role::class)->where('subject_id', $role->id)->latest('id')->firstOrFail();
        $this->assertSame([], $log->attribute_changes['old']['hiddenMenus']);
        $this->assertSame(['Artikel'], $log->attribute_changes['attributes']['hiddenMenus']);
    }

    public function test_new_role_shows_all_entries_by_default(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateRole::class)
            ->assertFormSet(['visible_menus' => [(string) $this->customers->id, (string) $this->articles->id]])
            ->fillForm(['name' => 'neu'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Role::findByName('neu')->hiddenMenus()->count());
    }

    /** B-112: Einträge der Hauptebene ohne Datentabelle (z. B. Datenmodell) im Formular und im Menü. */
    public function test_top_level_entries_of_the_app_can_be_hidden_too(): void
    {
        $this->actingAs($this->superAdmin());
        $app = $this->customers->fresh()->app;
        $model = Menu::create(['label' => 'Datenmodell global', 'route' => DataModelPage::ROUTE, 'app_id' => $app->id, 'sort_order' => 99]);
        $role = $this->role('vertrieb');
        $role->update(['app_id' => $app->id]);

        $page = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()]);
        $this->assertEqualsCanonicalizing([$this->customers->id, $this->articles->id, $model->id], array_map('intval', $page->get('data.visible_menus')));
        $page->fillForm(['visible_menus' => [(string) $this->customers->id, (string) $this->articles->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame([$model->id], $role->hiddenMenus()->pluck('menus.id')->all());

        // Alle Rollen der App blenden es aus: weg; eine zweite Rolle der App zeigt es: wieder da.
        $person = User::factory()->create()->assignRole($role);
        $this->assertContains($model->id, Menu::hiddenDataEntriesFor($person->fresh()));
        $other = $this->role('lager');
        $other->update(['app_id' => $app->id]);
        $person->assignRole($other);
        $this->assertNotContains($model->id, Menu::hiddenDataEntriesFor($person->fresh()));
    }
}
