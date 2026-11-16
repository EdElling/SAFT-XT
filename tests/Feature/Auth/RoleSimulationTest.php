<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\RoleSimulation;
use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Filament\Pages\RoleSimulationPage;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Services\DynamicSchemaService;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** „Ansicht als Rolle“: Super-Admins prüfen eine Rolle mit genau deren Rechten. */
class RoleSimulationTest extends DynamicDataTestCase
{
    private User $admin;

    private Role $sales;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();

        $kunden = app(DynamicSchemaService::class)->createTable(['name' => 'kunden', 'label' => 'Kunden'], [['name' => 'name', 'label' => 'Name', 'type' => 'string']]);
        $geheim = app(DynamicSchemaService::class)->createTable(['name' => 'geheim', 'label' => 'Geheim'], [['name' => 'code', 'label' => 'Code', 'type' => 'string']]);
        app(DataMenu::class)->ensure($kunden);
        app(DataMenu::class)->ensure($geheim);

        $this->sales = Role::create(['name' => 'vertrieb', 'guard_name' => 'web']);
        $this->sales->givePermissionTo([DataPermissions::name('kunden', 'view'), 'reports.view']);
    }

    public function test_super_admin_sees_saft_with_exactly_the_rights_of_the_role(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk()->assertSee('Ansicht als Rolle …')->assertDontSee('data-role-simulation-banner', false);

        Livewire::test(RoleSimulationPage::class)
            ->fillForm(['roles' => [$this->sales->id]])
            ->call('start')
            ->assertRedirect();

        $this->assertSame([$this->sales->id], session(RoleSimulation::SESSION_KEY));
        $this->assertSame(['rollen' => ['vertrieb']], Activity::query()->where('event', 'role_simulation_started')->sole()->properties->all());

        $this->get('/admin')->assertOk()
            ->assertSee('Ansicht als Rolle „vertrieb“.')
            ->assertDontSee('Ansicht als Rolle …', false);
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/data/geheim')->assertForbidden();
        $this->get('/admin/data/kunden')->assertOk();
        $this->get(RoleSimulationPage::getUrl())->assertForbidden();

        // Menü wie für die Rolle: Kunden ja, Geheim und Administration nein.
        $user = $this->admin->fresh();
        app(RoleSimulation::class)->apply($user);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertFalse($user->can('users.view'));
        $labels = collect(app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user)->getNavigation())
            ->flatMap(fn ($entry) => $entry instanceof NavigationGroup ? [$entry->getLabel(), ...collect($entry->getItems())->map->getLabel()->all()] : [$entry->getLabel()])
            ->map(fn ($label): string => (string) $label)->all();
        $this->assertContains('Kunden', $labels);
        $this->assertNotContains('Geheim', $labels);
        $this->assertNotContains('Administration', $labels);

        // Beenden geht immer, auch wenn die Rolle kaum Rechte hat.
        $this->post(route('role-simulation.stop'))->assertRedirect();
        $this->assertNull(session(RoleSimulation::SESSION_KEY));
        $this->assertSame(1, Activity::query()->where('event', 'role_simulation_stopped')->count());
        $this->get('/admin/users')->assertOk();
        $this->assertTrue($this->admin->fresh()->roles()->where('name', User::ROLE_SUPER_ADMIN)->exists(), 'Datenbank unverändert.');
    }

    public function test_only_real_super_admins_can_simulate(): void
    {
        $manager = $this->userWithPermissions(['roles.view', 'roles.update', 'users.view']);
        $this->actingAs($manager);

        $this->get(RoleSimulationPage::getUrl())->assertForbidden();
        $this->get('/admin')->assertDontSee('Ansicht als Rolle …');

        // Eine von Hand in die Sitzung gelegte Simulation wird verworfen.
        session([RoleSimulation::SESSION_KEY => [$this->sales->id]]);
        $this->get('/admin')->assertOk()->assertDontSee('data-role-simulation-banner', false);
        $this->assertNull(session(RoleSimulation::SESSION_KEY));

        // Die Rolle super-admin selbst lässt sich nicht simulieren.
        $this->expectException(HttpException::class);
        app(RoleSimulation::class)->start($this->admin, [Role::findByName(User::ROLE_SUPER_ADMIN)]);
    }

    public function test_several_roles_together_and_own_reports_do_not_count(): void
    {
        $this->actingAs($this->admin);
        $own = Report::create(['name' => 'Privat vom Admin', 'owner_id' => $this->admin->id, 'definition' => ['base' => 0, 'fields' => []]]);
        $default = Role::findByName(User::ROLE_DEFAULT);

        Livewire::test(RoleSimulationPage::class)
            ->assertFormSet(['roles' => [$default->id]])
            ->fillForm(['roles' => [$default->id, $this->sales->id]])
            ->call('start');

        $this->get('/admin')->assertOk()->assertSee('Ansicht als Rollen „benutzer“, „vertrieb“.');

        $user = $this->admin->fresh();
        app(RoleSimulation::class)->apply($user);
        $this->assertTrue($user->can('widgets.reports'), 'Rechte beider Rollen zusammen.');
        $this->assertTrue($user->can(DataPermissions::name('kunden', 'view')));
        $this->assertFalse($own->isOwnedBy($user), 'Während der Simulation gehört dem Admin nichts.');
        $this->assertSame([], Report::query()->visibleTo($user)->pluck('name')->all());
        $this->get(ReportResource::getUrl('view', ['record' => $own]))->assertNotFound();

        $this->post(route('role-simulation.stop'));
        $this->assertTrue($own->isOwnedBy($this->admin->fresh()));
    }
}
