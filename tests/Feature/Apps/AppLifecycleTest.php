<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\AppRemover;
use App\Apps\CurrentApp;
use App\DynamicData\DataAccess;
use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\CustomTables\Pages\ListCustomTables;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\TableConnector\Pages\ListConnectedTables;
use App\Models\App;
use App\Models\AppManual;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use App\Tasks\TaskRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Lebenszyklus einer App (B-107, Etappe 3): Rollenformular je App, deaktivieren, entfernen. */
class AppLifecycleTest extends DynamicDataTestCase
{
    private App $sales;

    private App $hr;

    private CustomTable $customers;

    private CustomTable $orders;

    private CustomTable $staff;

    private Role $salesRole;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->sales = self::ensureTestApp();
        $this->sales->update(['name' => ['de' => 'Verkauf'], 'sort_order' => 1]);
        $this->hr = App::query()->create(['name' => ['de' => 'Personal'], 'prefix' => 'hr', 'sort_order' => 2]);

        $this->customers = $this->service()->createTable(['app_id' => $this->sales->id, 'name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        // Verknüpfung innerhalb der App: hindert das gemeinsame Entfernen nicht.
        $this->orders = $this->service()->createTable(['app_id' => $this->sales->id, 'name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        $this->staff = $this->service()->createTable(['app_id' => $this->hr->id, 'name' => 'hr_mitarbeitende', 'label' => 'Mitarbeitende'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
        ]);

        foreach ([$this->customers, $this->orders, $this->staff] as $table) {
            app(DataMenu::class)->ensure($table);
        }

        $this->salesRole = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->sales->id]);
        $this->salesRole->givePermissionTo(['data.kunden.view', 'data.bestellungen.view']);
        $hrRole = Role::query()->create(['name' => 'personal', 'guard_name' => 'web', 'app_id' => $this->hr->id]);
        $hrRole->givePermissionTo(['data.hr_mitarbeitende.view']);
        $this->person = User::factory()->create();
        $this->person->assignRole([$this->salesRole, $hrRole, User::ROLE_DEFAULT]);
    }

    /** Plugin der App Personal (braucht deren Tabelle) mit einem eigenen Recht. */
    private function hrPlugin(): SaftPlugin
    {
        $plugin = new class extends SaftPlugin
        {
            public function id(): string
            {
                return 'hr-urlaub';
            }

            public function label(): string
            {
                return 'Urlaubsplanung';
            }

            public function requiredTables(): array
            {
                return ['hr_mitarbeitende' => ['name']];
            }

            public function permissions(): array
            {
                return ['plan' => 'Urlaub planen'];
            }
        };
        app(PluginRegistry::class)->add($plugin);
        Permission::findOrCreate('hr-urlaub.plan', 'web');

        return $plugin;
    }

    /** Sicherung ohne echtes mysqldump/pg_dump. */
    private function fakeBackup(): string
    {
        Storage::fake('local');
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });
        $storage = sys_get_temp_dir().'/saft-app-removal-'.uniqid();
        $this->app->useStoragePath($storage);

        return $storage;
    }

    public function test_role_form_follows_the_chosen_app_and_keeps_other_permissions_removable(): void
    {
        $this->hrPlugin();
        $this->actingAs($this->superAdmin());

        // Neue Rolle: ohne App keine Tabellen, sondern ein Hinweis; mit App nur deren Tabellen.
        Livewire::test(CreateRole::class)
            ->set('data.app_id', null)
            ->assertSee(__('roles.form.choose_app_first'))
            ->assertDontSeeHtml('data-matrix-table="kunden"')
            ->set('data.app_id', $this->hr->id)
            ->assertSee('Mitarbeitende')
            ->assertSee('Urlaubsplanung: Urlaub planen')
            ->set('data.app_id', $this->sales->id)
            ->assertDontSee('Urlaubsplanung: Urlaub planen');

        // Ältere Rolle mit Rechten aus einer anderen App: eigener Abschnitt, nur zum Entfernen.
        $this->salesRole->givePermissionTo(['data.hr_mitarbeitende.view', 'hr-urlaub.plan']);
        // Tabellen anderer Apps als Matrix, nur mit den vorhandenen Rechten; Plugins anderer Apps als Liste.
        $page = Livewire::test(EditRole::class, ['record' => $this->salesRole->getRouteKey()])
            ->assertSee(__('roles.form.other_permissions'))
            ->assertSeeHtml('data-matrix-cell="hr_mitarbeitende.view"')
            ->assertDontSeeHtml('data-matrix-cell="hr_mitarbeitende.create"')
            ->assertSee('Urlaubsplanung: Urlaub planen (Personal)')
            ->assertSet('data.other_data_permissions.hr_mitarbeitende', ['view']);

        $page->set('data.other_data_permissions.hr_mitarbeitende', [])->call('save')->assertHasNoFormErrors();

        $names = $this->salesRole->fresh()->permissions->pluck('name')->all();
        $this->assertNotContains('data.hr_mitarbeitende.view', $names, 'Abgewählt: entfernt.');
        $this->assertContains('hr-urlaub.plan', $names, 'Angehakt: bleibt.');
        $this->assertContains('data.kunden.view', $names, 'Rechte der eigenen App unberührt.');
    }

    public function test_switching_the_app_of_a_role_keeps_all_permissions_until_they_are_unticked(): void
    {
        $this->hrPlugin();
        $this->actingAs($this->superAdmin());
        // Dazu ein Recht auf eine Tabelle von Personal: nach dem Wechsel in der Matrix der App angehakt.
        $this->salesRole->givePermissionTo(['hr-urlaub.plan', 'data.hr_mitarbeitende.view']);
        $before = $this->salesRole->permissions->pluck('name')->sort()->values()->all();

        // Wechsel nach Personal: Rechte von Verkauf erscheinen „ausserhalb“, angehakt; Speichern ändert nichts.
        Livewire::test(EditRole::class, ['record' => $this->salesRole->getRouteKey()])
            ->set('data.app_id', $this->hr->id)
            ->assertSet('data.other_data_permissions.kunden', ['view'])
            ->assertSet('data.other_data_permissions.bestellungen', ['view'])
            ->assertSet('data.data_permissions.hr_mitarbeitende', ['view'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($before, $this->salesRole->fresh()->permissions->pluck('name')->sort()->values()->all());
        $this->assertSame($this->hr->id, $this->salesRole->fresh()->app_id);
    }

    public function test_admin_lists_choose_the_app_from_a_select(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        app(CurrentApp::class)->switch($admin, $this->hr);

        // Auswahlliste (statt Reitern) je App in der Reihenfolge der Seite „Apps“, „System“ (wo es Einträge ohne App gibt)
        // und „Alle“, mit Anzahl; vorgewählt die aktuelle App, die Spalte „App“ nur unter „Alle“.
        Livewire::test(ListCustomTables::class)
            ->assertSet('activeTab', 'app-'.$this->hr->id)
            ->assertSeeHtml('<select')
            ->assertSeeHtml('saft-app-select')
            ->assertSee($this->hr->name.' (1)')
            ->assertDontSeeHtml('fi-tabs-item')
            ->assertCanSeeTableRecords([$this->staff])
            ->assertCanNotSeeTableRecords([$this->customers])
            ->assertTableColumnHidden('app.name')
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$this->staff, $this->customers])
            ->assertTableColumnVisible('app.name');
        $this->assertSame(['app-'.$this->sales->id, 'app-'.$this->hr->id, 'all'], array_keys(Livewire::test(ListCustomTables::class)->instance()->getTabs()));
        $this->assertArrayHasKey('system', Livewire::test(ListRoles::class)->instance()->getTabs());

        $this->assertNull(Livewire::test(ListConnectedTables::class)->instance()->getTable()->getFilter('app_id'), 'Kein Filter „App“ mehr.');
    }

    public function test_a_deactivated_app_is_hidden_except_for_the_super_admin_and_its_tasks_skip(): void
    {
        $this->assertTrue(DataAccess::allows($this->person, $this->staff, 'view'));
        $this->hr->update(['is_active' => false]);

        $current = app(CurrentApp::class);
        $this->assertSame(['Verkauf'], $current->accessible($this->person)->map(fn (App $a): string => (string) $a->name)->all());
        $this->assertFalse(DataAccess::allows($this->person, $this->staff, 'view'), 'Kein Zugriff auf Tabellen der deaktivierten App.');
        $this->assertTrue(DataAccess::allows($this->person, $this->customers, 'view'));

        $admin = $this->superAdmin();
        $this->assertCount(2, $current->accessible($admin), 'Der Super-Admin sieht sie weiterhin.');
        $this->assertTrue(DataAccess::allows($admin, $this->staff, 'view'));

        $task = ScheduledTask::query()->create(['name' => 'Datenstand', 'type' => 'task_runs_prune', 'app_id' => $this->hr->id, 'frequency' => 'daily', 'parameters' => []]);
        $run = app(TaskRunner::class)->run($task, TaskRun::MANUAL);
        $this->assertSame(ScheduledTask::SKIPPED, $run->status);
        $this->assertStringContainsString('Personal', (string) $run->message);

        // Aktivieren und Deaktivieren über die Seite „Apps“.
        $this->actingAs($admin);
        Livewire::test(ListApps::class)->callTableAction('activate', $this->hr);
        $this->assertTrue($this->hr->fresh()->is_active);
        Livewire::test(ListApps::class)->callTableAction('deactivate', $this->hr);
        $this->assertFalse($this->hr->fresh()->is_active);
    }

    public function test_removal_is_for_the_super_admin_only_confirmed_twice_and_delayed(): void
    {
        $remover = app(AppRemover::class);
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        // Aktive App: erst deaktivieren.
        $this->assertContains(__('apps.removal.deactivate_first'), $remover->blockers($this->sales));
        $this->sales->update(['is_active' => false]);
        $this->assertSame([], $remover->blockers($this->sales), 'Verknüpfung innerhalb der App hindert nicht.');

        // Nur der Super-Admin; ein Recht dafür gibt es nicht.
        $appAdmin = $this->userWithPermissions(['apps.view', 'apps.manage']);
        $this->actingAs($appAdmin);
        Livewire::test(ListApps::class)->assertTableActionHidden('requestRemoval', $this->sales);
        $this->expectExceptionOnRequest(fn () => $remover->request($this->sales, $appAdmin, 'Verkauf'), __('apps.removal.super_admin_only'));

        // Zwei Schritte: Übersicht bestätigen, Namen eingeben.
        $this->actingAs($admin);
        Livewire::test(ListApps::class)
            ->mountTableAction('requestRemoval', $this->sales)
            ->setTableActionData(['understood' => true, 'confirmation' => 'Falsch'])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['confirmation']);
        $this->assertNull($this->sales->fresh()->removal_due_at);

        Livewire::test(ListApps::class)
            ->callTableAction('requestRemoval', $this->sales, data: ['understood' => true, 'confirmation' => 'Verkauf'])
            ->assertHasNoTableActionErrors();
        $sales = $this->sales->fresh();
        $this->assertTrue($sales->removalPending());
        $this->assertFalse($sales->removalDue(), 'Wartezeit läuft.');
        $this->assertEqualsWithDelta(now()->addMinutes(AppRemover::DELAY_MINUTES)->timestamp, $sales->removal_due_at->timestamp, 5);

        // Während der Wartezeit: nicht ausführbar, nicht aktivierbar, aber abbrechbar.
        $this->expectExceptionOnRequest(fn () => $remover->run($sales, $admin), __('apps.removal.not_due'));
        $this->expectExceptionOnRequest(fn () => $sales->update(['is_active' => true]), __('apps.removal.cancel_first'));
        Livewire::test(ListApps::class)->assertTableActionHidden('runRemoval', $sales)->callTableAction('cancelRemoval', $sales);
        $this->assertFalse($this->sales->fresh()->removalPending());
    }

    public function test_removal_runs_after_the_waiting_time_and_removes_everything_of_the_app(): void
    {
        $storage = $this->fakeBackup();
        $admin = $this->superAdmin();
        $this->sales->update(['is_active' => false]);
        Report::query()->create(['name' => 'Kundenliste', 'owner_id' => $admin->id, 'is_shared' => true, 'definition' => ['base' => $this->customers->id]]);
        ScheduledTask::query()->create(['name' => 'Aufräumen', 'type' => 'task_runs_prune', 'app_id' => $this->sales->id, 'frequency' => 'daily', 'parameters' => []]);
        AppManual::query()->create(['app_id' => $this->sales->id, 'locale' => 'de', 'content' => '# Verkauf']);
        $this->person->forceFill(['current_app_id' => $this->sales->id])->save();

        $overview = app(AppRemover::class)->overview($this->sales);
        $this->assertSame(['bestellungen', 'kunden'], array_column($overview['tables'], 'name'));
        $this->assertSame([['name' => 'vertrieb', 'members' => 1]], $overview['roles']);
        $this->assertSame(0, $overview['users_without_app'], 'Die Person hat noch Personal.');

        app(AppRemover::class)->request($this->sales, $admin, 'Verkauf');
        $this->travel(AppRemover::DELAY_MINUTES + 1)->minutes();

        // Ausgeführt vom Scheduler.
        $this->artisan('tasks:run-due')->assertSuccessful();

        $this->assertNull(App::query()->find($this->sales->id));
        $this->assertFalse($this->appSchema()->hasTable($this->qualified('kunden')));
        $this->assertFalse($this->appSchema()->hasTable($this->qualified('bestellungen')));
        $this->assertTrue($this->appSchema()->hasTable($this->qualified('hr_mitarbeitende')), 'Andere Apps bleiben.');
        $this->assertSame(0, CustomTable::query()->whereIn('name', ['kunden', 'bestellungen'])->count());
        $this->assertSame(0, Report::query()->count());
        $this->assertSame(0, ScheduledTask::query()->where('name', 'Aufräumen')->count());
        $this->assertSame(0, AppManual::query()->count());
        $this->assertSame(0, Menu::query()->where('app_id', $this->sales->id)->count());
        $this->assertNull(Role::query()->where('name', 'vertrieb')->first());
        $this->assertSame(0, Permission::query()->where('name', 'like', 'data.kunden.%')->count());
        $this->assertNull($this->person->fresh()->current_app_id);
        $this->assertCount(1, File::glob($storage.'/backups/*'), 'Vorher gesichert.');
        File::deleteDirectory($storage);
    }

    public function test_plugins_of_the_app_block_the_removal(): void
    {
        $this->hrPlugin();
        $this->hr->update(['is_active' => false]);

        $blockers = app(AppRemover::class)->blockers($this->hr);
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Urlaubsplanung', $blockers[0]);
    }

    private function expectExceptionOnRequest(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Erwartete Ablehnung: '.$message);
        } catch (BusinessRuleException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
