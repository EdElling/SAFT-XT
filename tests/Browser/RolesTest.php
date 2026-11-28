<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\DuskTestCase;

/** Rollen und Rechte (Bereich ROL). */
class RolesTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string USER = 'vertrieb@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $schema = app(DynamicSchemaService::class);

        foreach (['kunden' => 'Kunden', 'bestellungen' => 'Bestellungen', 'artikel' => 'Artikel'] as $name => $label) {
            $table = $schema->createTable(['name' => $name, 'label' => $label], [
                ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ]);
            DynamicRecord::forTable($table)->create(['titel' => $label.' Eins']);
            app(DataMenu::class)->ensure($table);
        }
    }

    /** @param  array<string, list<string>>  $rights Tabelle => Aktionen */
    private function role(string $name, array $rights): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);

        foreach ($rights as $table => $actions) {
            $role->givePermissionTo(array_map(static fn (string $action): string => DataPermissions::name($table, $action), $actions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    private function userWith(Role ...$roles): User
    {
        return $this->activeUser(self::USER)->assignRole($roles);
    }

    private function dataRight(string $table, string $action): string
    {
        return "input[data-matrix-cell=\"{$table}.{$action}\"]";
    }

    /** @return list<string> */
    private function rights(Role $role, string $table): array
    {
        return $role->fresh()->permissions()->where('name', 'like', "data.{$table}.%")->orderBy('name')->pluck('name')->all();
    }

    /** ROL-01 */
    public function test_new_role_keeps_data_rights_from_the_matrix(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/roles/create')->waitFor('input[id="form.name"]', 15)
                ->type('input[id="form.name"]', 'vertrieb');
            $this->openTab($browser, 'Datenrechte')
                ->click($this->dataRight('kunden', 'view'))
                ->click($this->dataRight('kunden', 'create'));
            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitUsing(15, 100, static fn (): bool => Role::query()->where('name', 'vertrieb')->exists())
                ->pause(600);

            $role = Role::query()->where('name', 'vertrieb')->firstOrFail();
            $this->assertSame(['data.kunden.create', 'data.kunden.view'], $this->rights($role, 'kunden'));

            $browser->visit("/admin/roles/{$role->id}/edit?tab=data-rights")->waitFor($this->dataRight('kunden', 'view'), 15);
            $this->assertTrue($browser->element($this->dataRight('kunden', 'view'))->isSelected());
            $this->assertTrue($browser->element($this->dataRight('kunden', 'create'))->isSelected());
            $this->assertFalse($browser->element($this->dataRight('kunden', 'delete'))->isSelected());
            $this->assertFalse($browser->element($this->dataRight('artikel', 'view'))->isSelected());
        });
    }

    /** ROL-02 */
    public function test_matrix_select_all_and_single_boxes_save_exactly_the_choice(): void
    {
        $role = $this->role('vertrieb', []);

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, self::ADMIN)->visit("/admin/roles/{$role->id}/edit?tab=data-rights")->waitFor($this->dataRight('kunden', 'view'), 15);

            // Matrix: „Alle“ in der Zeile der Tabelle, Spaltenköpfe mit den Rechten.
            $this->assertStringContainsString('Anzeigen', $browser->text('[data-permission-matrix] thead'));

            $browser->click('input[data-matrix-all="kunden"]')
                ->waitUsing(15, 100, fn (): bool => $browser->element($this->dataRight('kunden', 'delete'))->isSelected())
                ->click($this->dataRight('kunden', 'delete'))
                ->press('Speichern')
                ->waitForText('Gespeichert', 15);

            $this->assertSame(['data.kunden.create', 'data.kunden.export', 'data.kunden.import', 'data.kunden.update', 'data.kunden.view'], $this->rights($role, 'kunden'));
            $this->assertSame([], $this->rights($role, 'artikel'));
        });
    }

    /** ROL-03 */
    public function test_sales_role_sees_only_its_tables_and_actions(): void
    {
        $all = ['view', 'create', 'update', 'delete'];
        $this->userWith($this->role('vertrieb', ['kunden' => $all, 'bestellungen' => $all, 'artikel' => ['view']]));

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::USER)
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Kunden')
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Bestellungen')
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Artikel')
                ->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Benutzer')
                ->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Table-Designer');

            $browser->visit('/admin/data/kunden')->waitForText('Kunden Eins', 15)->assertSee('Neuer Datensatz');
            $this->openDetails($browser, 'Kunden Eins')->assertSee('Bearbeiten')->assertSee('Löschen');

            $browser->visit('/admin/data/artikel')->waitForText('Artikel Eins', 15)->assertDontSee('Neuer Datensatz');
            $this->openDetails($browser, 'Artikel Eins')->assertDontSee('Bearbeiten')->assertDontSee('Löschen')->assertSee('Verlauf');

            $browser->visit('/admin/users')->waitForText('Keine Berechtigung', 15);
        });
    }

    /** ROL-04 */
    public function test_changed_rights_take_effect_without_new_login(): void
    {
        $role = $this->role('vertrieb', ['kunden' => ['view']]);
        $this->userWith($role);

        $this->browse(function (Browser $admin, Browser $user) use ($role): void {
            $this->login($user, self::USER)->visit('/admin/data/kunden')->waitForText('Kunden Eins', 15);

            $this->login($admin, self::ADMIN)->visit("/admin/roles/{$role->id}/edit?tab=data-rights")
                ->waitFor($this->dataRight('kunden', 'view'), 15)
                ->click($this->dataRight('kunden', 'view'))
                ->press('Speichern')
                ->waitForText('Gespeichert', 15);

            $this->clickRow($user, 'Kunden Eins')->waitForText('Keine Berechtigung', 15);

            $user->visit('/admin/data/kunden')->waitForText('Keine Berechtigung', 15);
            $user->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Kunden');
        });
    }

    /** ROL-05 */
    public function test_two_roles_grant_the_sum_of_their_rights(): void
    {
        $this->userWith($this->role('lesen_kunden', ['kunden' => ['view']]), $this->role('anlegen_artikel', ['artikel' => ['view', 'create']]));

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::USER)
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Kunden')
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Artikel')
                ->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Bestellungen');

            $browser->visit('/admin/data/kunden')->waitForText('Kunden Eins', 15)->assertDontSee('Neuer Datensatz');
            $browser->visit('/admin/data/artikel')->waitForText('Artikel Eins', 15)->assertSee('Neuer Datensatz');
            $browser->visit('/admin/data/bestellungen')->waitForText('Keine Berechtigung', 15);
        });
    }

    /** ROL-06 */
    public function test_deleting_a_role_removes_exactly_its_rights_from_users(): void
    {
        $role = $this->role('vertrieb', ['kunden' => ['view']]);
        $user = $this->userWith($role, $this->role('bleibt', ['artikel' => ['view']]));

        $this->browse(function (Browser $admin, Browser $affected) use ($role): void {
            $this->login($affected, self::USER)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Kunden');

            $this->login($admin, self::ADMIN)->visit('/admin/roles')->waitForText('vertrieb', 15);
            $this->rowAction($admin, 'vertrieb', 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($admin, 'Löschen')->waitUntilMissingText('vertrieb', 15);

            $this->assertNull(Role::query()->find($role->id));

            $affected->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)
                ->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Kunden')
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Artikel');
        });

        $this->assertNotNull(User::query()->find($user->id));
    }

    /** ROL-07 */
    public function test_super_admin_role_cannot_be_edited_or_deleted(): void
    {
        $role = Role::findByName(User::ROLE_SUPER_ADMIN, 'web');

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, self::ADMIN)->visit('/admin/roles?tab=system')->waitForText('super-admin', 15);

            $actions = $browser->driver->findElements(WebDriverBy::xpath(
                "//tr[contains(@class, 'fi-ta-row')][contains(., 'super-admin')]//*[self::button or self::a][contains(., 'Bearbeiten') or contains(., 'Löschen') or @aria-label='Bearbeiten' or @aria-label='Löschen']",
            ));
            $this->assertSame([], $actions);

            $browser->visit("/admin/roles/{$role->id}/edit")->waitForText('Keine Berechtigung', 15);
        });
    }

    /** ROL-08 */
    public function test_default_role_cannot_be_deleted(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/roles?tab=system')->waitForText(User::ROLE_DEFAULT, 15);
            $this->rowAction($browser, User::ROLE_DEFAULT, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')->waitForText('Standardrolle', 15);

            $this->assertNotNull(Role::query()->where('name', User::ROLE_DEFAULT)->first());
        });
    }

    /** ROL-09 */
    public function test_permissions_have_readable_german_names(): void
    {
        $this->browse(function (Browser $browser): void {
            // Kurz warten: Livewire rendert die Liste nach, sonst werden Elemente beim Lesen ersetzt.
            $this->login($browser, self::ADMIN)->visit('/admin/roles/create')->waitForText('Datenrechte', 15)->pause(500);

            $labels = array_map(
                static fn ($label): string => trim($label->getText()),
                $browser->elements('.fi-fo-checkbox-list-option-label'),
            );

            $this->assertGreaterThan(20, count($labels));

            foreach ($labels as $label) {
                $this->assertDoesNotMatchRegularExpression('/[._]|\b(view|create|update|delete)\b/', $label);
            }

            $browser->assertSee('Benutzer: anzeigen')->assertSee('Dashboard-Widget: Benutzerübersicht');
        });

        $this->assertSame(3, CustomTable::query()->count());
    }

    /** Reiter der Seite „Rolle bearbeiten“ öffnen (B-175). */
    private function openTab(Browser $browser, string $label): Browser
    {
        $browser->driver->findElement(WebDriverBy::xpath("//*[@role='tab'][normalize-space(.)='{$label}']"))->click();

        return $browser->pause(300);
    }

    /** ROL-16 */
    public function test_role_tabs_keep_the_active_tab_and_show_errors_above(): void
    {
        $role = $this->role('vertrieb', []);

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, self::ADMIN)->visit("/admin/roles/{$role->id}/edit")->waitForText('Benutzer: anzeigen', 15)
                ->assertMissing($this->dataRight('kunden', 'view'));

            // Reiter wechseln: Inhalt sichtbar, Reiter in der Adresse.
            $this->openTab($browser, 'Datenrechte')->waitFor($this->dataRight('kunden', 'view'), 15)
                ->assertDontSee('Benutzer: anzeigen')
                ->assertQueryStringHas('tab', 'data-rights');

            // Speichern lässt den Reiter stehen.
            $browser->click($this->dataRight('kunden', 'view'))
                ->press('Speichern')
                ->waitForText('Gespeichert', 15)
                ->assertVisible($this->dataRight('kunden', 'view'));
            $this->assertSame(['data.kunden.view'], $this->rights($role, 'kunden'));

            // Neu laden: derselbe Reiter.
            $browser->refresh()->waitFor($this->dataRight('kunden', 'view'), 15)->assertDontSee('Benutzer: anzeigen');

            // Prüffehler beim Namen (nur Buchstaben, Ziffern, - und _) steht über den Reitern und ist sichtbar.
            $browser->type('input[id="form.name"]', 'mit leerzeichen')
                ->press('Speichern')
                ->waitFor('[data-validation-error]', 15)
                ->assertVisible('[data-validation-error]');
            $this->assertSame('vertrieb', $role->fresh()->name);
        });
    }

    private function menuEntry(string $table): string
    {
        $id = (int) Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', $table)->value('id');

        return "input[wire\\:model=\"data.visible_menus\"][value=\"{$id}\"]";
    }

    /** ROL-10 */
    public function test_menu_entry_can_be_hidden_for_a_role_without_removing_access(): void
    {
        $role = $this->role('vertrieb', ['kunden' => ['view'], 'artikel' => ['view']]);
        $this->userWith($role);

        $this->browse(function (Browser $admin, Browser $user) use ($role): void {
            $this->login($admin, self::ADMIN)->visit("/admin/roles/{$role->id}/edit")->waitForText('Menüeinträge', 15);
            $this->openTab($admin, 'Menüeinträge')->waitFor($this->menuEntry('artikel'), 15);
            // Vorgabe: alle Einträge sichtbar.
            $this->assertTrue($admin->element($this->menuEntry('artikel'))->isSelected());
            $this->assertTrue($admin->element($this->menuEntry('kunden'))->isSelected());

            $admin->click($this->menuEntry('artikel'))->press('Speichern')->waitForText('Gespeichert', 15);

            $this->login($user, self::USER)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Kunden')->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Artikel');
            // Nur ausgeblendet: Die Tabelle bleibt erreichbar.
            $user->visit('/admin/data/artikel')->waitForText('Artikel Eins', 15)->assertDontSee('Keine Berechtigung');

            // Der Reiter steht in der Adresse und bleibt nach dem Neuladen.
            $admin->refresh()->waitFor($this->menuEntry('artikel'), 15);
            $this->assertFalse($admin->element($this->menuEntry('artikel'))->isSelected());
        });
    }

    /** ROL-11 */
    public function test_one_role_showing_the_menu_entry_is_enough(): void
    {
        $hiding = $this->role('vertrieb', ['artikel' => ['view']]);
        $hiding->hiddenMenus()->sync([(int) Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'artikel')->value('id')]);
        $this->userWith($hiding, $this->role('lager', ['artikel' => ['view']]));

        $this->browse(function (Browser $user): void {
            $this->login($user, self::USER)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Artikel');
        });
    }

    /** ROL-13 */
    public function test_matrix_search_and_whole_column(): void
    {
        $role = $this->role('vertrieb', []);

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, self::ADMIN)->visit("/admin/roles/{$role->id}/edit?tab=data-rights")->waitFor($this->dataRight('kunden', 'view'), 15);

            // Suche blendet Zeilen aus; „ganze Spalte“ wirkt nur auf die sichtbaren.
            $browser->type('[data-permission-matrix] input[type="search"]', 'kund')->pause(300)
                ->assertVisible('tr[data-matrix-row="kunden"]')
                ->assertMissing('tr[data-matrix-row="artikel"]')
                ->click('input[data-matrix-column="view"]')
                ->waitUsing(15, 100, fn (): bool => $browser->element($this->dataRight('kunden', 'view'))->isSelected())
                ->press('Speichern')
                ->waitForText('Gespeichert', 15);

            $this->assertSame(['data.kunden.view'], $this->rights($role, 'kunden'));
            $this->assertSame([], $this->rights($role, 'artikel'));
        });
    }

    /** ROL-14 */
    public function test_super_admin_views_saft_as_a_role(): void
    {
        $this->role('vertrieb', ['kunden' => ['view']]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin')->waitFor('.fi-user-menu-trigger', 15)
                ->click('.fi-user-menu-trigger')->waitForText('Ansicht als Rolle …', 15)
                ->clickLink('Ansicht als Rolle …')
                ->waitForText('Rollen', 15)
                ->check('input[type="checkbox"][value="'.Role::findByName('vertrieb')->id.'"]')
                ->press('Ansicht starten')
                ->waitFor('[data-role-simulation-banner]', 15)
                // „benutzer“ ist vorausgewählt (wie bei fast jeder Person), dazu „vertrieb“.
                ->assertSeeIn('[data-role-simulation-banner]', 'Ansicht als Rollen „benutzer“, „vertrieb“.')
                ->assertSeeIn('.fi-sidebar', 'Kunden')
                ->assertDontSeeIn('.fi-sidebar', 'Benutzer');

            // Erst klicken, wenn die Seite ruhig ist; gezielt den Button im Hinweisband.
            $browser->pause(500)
                ->click('[data-role-simulation-banner] button[type="submit"]')
                ->waitUntilMissing('[data-role-simulation-banner]', 15)
                ->waitForText('Benutzer', 15)
                ->assertSeeIn('.fi-sidebar', 'Benutzer');
        });
    }
}
