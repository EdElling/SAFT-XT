<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Filament\Pages\PluginsPage;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\ScheduledTasks\Pages\CreateScheduledTask;
use App\Models\Menu;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Plugins\PluginCleanup;
use App\Plugins\PluginMenus;
use App\Plugins\PluginPermissions;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use App\Tasks\TaskRegistry;
use App\Tasks\Types\TaskRunsPruneTask;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Livewire\Livewire;
use Saft\LagerUmbuchung\LagerUmbuchungPlugin;
use Saft\LagerUmbuchung\Pages\Umbuchung;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Plugin-Rahmen: Anmeldung, Rechte, Menüvorschläge, Kompatibilität, Seite „Plugins“, Aufräumen. */
class PluginFrameworkTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const string PERMISSION = 'lager-umbuchung.execute';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    private function plugin(): SaftPlugin
    {
        return app(PluginRegistry::class)->get(LagerUmbuchungPlugin::ID);
    }

    public function test_installed_plugin_is_registered_in_the_panel(): void
    {
        $this->assertTrue(app(PluginRegistry::class)->has('lager-umbuchung'));
        $this->assertArrayHasKey('lager-umbuchung', Filament::getPanel('admin')->getPlugins());
        $this->assertTrue(Route::has(Umbuchung::ROUTE), 'Seiten unter src/Pages werden gefunden.');

        $plugin = $this->plugin();
        $this->assertSame('saft/lager-umbuchung', $plugin->packageName());
        $this->assertSame('1.3.0', $plugin->version());
        $this->assertSame('^1.2', $plugin->requiresApi());
        $this->assertSame([self::PERMISSION], $plugin->permissionNames());
    }

    public function test_compatibility_follows_the_plugin_api_not_the_saft_version(): void
    {
        $this->assertTrue($this->plugin()->isCompatible());

        // Das Plugin gibt nur die API an (extra.saft.api): neue SAFT-Versionen ändern nichts.
        config(['saft.version' => '3.0.0']);
        $this->assertTrue($this->plugin()->isCompatible());

        config(['saft.api_versions' => ['2.0']]);
        $this->assertFalse($this->plugin()->isCompatible());
    }

    public function test_plugin_ids_are_checked(): void
    {
        $registry = new PluginRegistry;

        try {
            $registry->add(self::fake('Lager_Umbuchung'));
            $this->fail('Ungültige id angenommen.');
        } catch (InvalidArgumentException) {
        }

        $registry->add(self::fake('demo'));
        $this->expectException(InvalidArgumentException::class);
        $registry->add(new class extends SaftPlugin
        {
            public function id(): string
            {
                return 'demo';
            }

            public function label(): string
            {
                return 'Doppelt';
            }
        });
    }

    /** @param  list<class-string>  $taskTypes */
    private static function fake(string $id, array $permissions = [], array $taskTypes = []): SaftPlugin
    {
        return new class($id, $permissions, $taskTypes) extends SaftPlugin
        {
            /**
             * @param  array<string, string>  $rights
             * @param  list<class-string>  $tasks
             */
            public function __construct(private readonly string $key, private readonly array $rights, private readonly array $tasks) {}

            public function id(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Test-Plugin';
            }

            public function permissions(): array
            {
                return $this->rights;
            }

            public function taskTypes(): array
            {
                return $this->tasks;
            }
        };
    }

    public function test_permissions_are_created_after_migrate_and_labelled(): void
    {
        $this->assertTrue(Permission::query()->where('name', self::PERMISSION)->exists(), 'Nach „migrate“ angelegt.');

        Permission::query()->where('name', self::PERMISSION)->delete();
        $this->assertSame([self::PERMISSION], app(PluginPermissions::class)->sync());
        $this->assertSame([], app(PluginPermissions::class)->sync(), 'Zweiter Lauf ändert nichts.');

        app()->setLocale('de');
        $this->assertSame('Lager-Umbuchung: Umbuchungen ausführen', RoleForm::label(self::PERMISSION));
        $this->assertSame('Plugins: verwalten', RoleForm::label('plugins.manage'));
        app()->setLocale('fr');
        $this->assertSame('Transfert de stock: Effectuer des transferts', RoleForm::label(self::PERMISSION));
    }

    public function test_task_types_of_plugins_are_offered(): void
    {
        app(PluginRegistry::class)->add(self::fake('aufgaben', taskTypes: [TaskRunsPruneTask::class]));
        $this->app->forgetScopedInstances();

        $this->assertArrayHasKey('task_runs_prune', app(TaskRegistry::class)->all());
    }

    public function test_task_type_of_a_plugin_is_created_with_its_parameter_defaults(): void
    {
        $this->actingAs($this->userWithPermissions(['tasks.view', 'tasks.create']));

        // Die Wahl der Art belegt die Parameter mit ihren Vorgaben (im Browser gingen Werte sonst verloren).
        Livewire::test(CreateScheduledTask::class)
            ->fillForm(['type' => 'lager_tafel_inventory_reminder'])
            ->assertFormSet(['parameters.days' => 90])
            ->fillForm(['parameters.days' => '30', 'frequency' => 'daily', 'at_time' => '06:00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['days' => 30], array_map('intval', ScheduledTask::query()->where('type', 'lager_tafel_inventory_reminder')->sole()->parameterValues()));
    }

    public function test_menu_suggestions_are_adopted_once(): void
    {
        $menus = app(PluginMenus::class);
        $this->assertCount(1, $menus->missing($this->plugin()));

        $this->assertSame(1, $menus->adopt($this->plugin()));
        $this->assertSame(0, $menus->adopt($this->plugin()), 'Idempotent.');

        $entry = Menu::query()->where('route', Umbuchung::ROUTE)->sole();
        $this->assertSame(self::PERMISSION, $entry->permission_name);
        $this->assertSame('Lager (Demo)', $entry->parent?->getTranslation('label', 'de'), 'Gruppe neu angelegt.');
        $this->assertSame('Transfert', $entry->getTranslation('label', 'fr'));
    }

    public function test_plugins_page_needs_view_and_adopting_needs_manage(): void
    {
        $this->actingAs($this->userWithPermissions([]));
        $this->get(PluginsPage::getUrl())->assertForbidden();

        $this->actingAs($this->userWithPermissions(['plugins.view']));
        Livewire::test(PluginsPage::class)
            ->assertSee('Lager-Umbuchung')
            ->assertSee('0 von 1 übernommen')
            ->assertTableActionHidden('adoptMenus', 'lager-umbuchung');

        $this->actingAs($this->userWithPermissions(['plugins.view', 'plugins.manage']));
        Livewire::test(PluginsPage::class)
            ->callTableAction('adoptMenus', 'lager-umbuchung')
            ->assertNotified();
        $this->assertTrue(Menu::query()->where('route', Umbuchung::ROUTE)->exists());
    }

    public function test_plugin_page_needs_the_plugin_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['plugins.view']));
        $this->get(Umbuchung::getUrl())->assertForbidden();

        $this->actingAs($this->userWithPermissions([self::PERMISSION]));
        $this->get(Umbuchung::getUrl())->assertOk();
    }

    public function test_leftovers_of_removed_plugins_are_found_and_removed_on_request(): void
    {
        $cleanup = app(PluginCleanup::class);
        $this->assertSame([], $cleanup->orphanedMenus(), 'SAFT selbst hinterlässt nichts.');
        $this->assertSame([], $cleanup->orphanedPermissions());

        $group = Menu::create(['label' => ['de' => 'Altes Plugin'], 'sort_order' => 500, 'is_active' => true]);
        $entry = Menu::create(['label' => ['de' => 'Weg'], 'parent_id' => $group->id, 'route' => 'filament.admin.pages.weg', 'sort_order' => 1, 'is_active' => true]);
        $permission = Permission::findOrCreate('altes-plugin.execute', 'web');
        Permission::findOrCreate('data.kunden.view', 'web');

        $this->assertSame([$entry->id], array_keys($cleanup->orphanedMenus()));
        $this->assertSame([$permission->id => 'altes-plugin.execute'], $cleanup->orphanedPermissions());

        $this->actingAs($this->userWithPermissions(['plugins.view']));
        Livewire::test(PluginsPage::class)->assertSee('Überbleibsel entfernter Plugins')->assertActionHidden('cleanup');

        $this->actingAs($this->userWithPermissions(['plugins.view', 'plugins.manage']));
        Livewire::test(PluginsPage::class)
            ->mountAction('cleanup')
            ->assertActionDataSet(['menus' => [$entry->id], 'permissions' => []])
            ->callMountedAction()
            ->assertNotified();

        $this->assertNull(Menu::query()->find($entry->id));
        $this->assertNull(Menu::query()->find($group->id), 'Leere Gruppe ebenfalls entfernt.');
        $this->assertTrue(Permission::query()->whereKey($permission->id)->exists(), 'Rechte nur, wenn ausgewählt.');

        $this->assertSame(['menus' => 0, 'permissions' => 1], $cleanup->remove([], [$permission->id]));
        $this->assertFalse(Permission::query()->whereKey($permission->id)->exists());
        $this->assertSame(['menus' => 0, 'permissions' => 0], $cleanup->remove([], [(int) Permission::findByName('users.view', 'web')->id]), 'Nur Verwaistes.');
    }

    public function test_hidden_menu_entry_is_logged_once_a_day(): void
    {
        Cache::flush();
        Menu::create(['label' => ['de' => 'Weg'], 'route' => 'filament.admin.pages.weg', 'sort_order' => 1, 'is_active' => true]);
        $user = User::query()->find($this->superAdmin()->id);

        Log::spy();
        app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user);
        app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user);

        Log::shouldHaveReceived('warning')->with('Menü-Eintrag verweist auf unbekannte Route und wird ausgeblendet.', \Mockery::any())->once();
    }

    public function test_console_lists_plugins(): void
    {
        $this->artisan('saft:plugins')
            ->expectsOutputToContain('lager-umbuchung')
            ->assertSuccessful();
    }
}
