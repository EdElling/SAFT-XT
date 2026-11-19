<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Documentation\Documentation;
use App\DynamicData\DataMenu;
use App\Models\App;
use App\Models\AppManual;
use App\Models\CustomTable;
use App\Models\Role;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Apps (Fachanwendungen, Bereich FAP, B-107). */
class AppsTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private App $first;

    private App $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $this->first = App::query()->firstOrFail();
        $this->second = App::query()->create(['name' => ['de' => 'Personal'], 'prefix' => 'hr', 'sort_order' => 2]);
        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['app_id' => $this->first->id, 'name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $schema->createTable(['app_id' => $this->second->id, 'name' => 'hr_mitarbeitende', 'label' => 'Mitarbeitende'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
        ]);
    }

    /** FAP-01 */
    public function test_app_is_created_with_suggested_prefix_and_only_empty_apps_can_be_deleted(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15)
                ->clickLink('Erstellen')
                ->waitFor('input[id="form.name.de"]', 15)
                ->type('input[id="form.name.de"]', 'Fuhrpark')
                ->keys('input[id="form.name.de"]', '{tab}')
                ->waitUsing(15, 100, fn (): bool => $browser->value('input[id="form.prefix"]') === 'fuhrpark')
                ->type('input[id="form.name.de"]', 'Fahrzeuge')
                ->keys('input[id="form.name.de"]', '{tab}')
                ->waitUsing(15, 100, fn (): bool => $browser->value('input[id="form.prefix"]') === 'fahrzeuge');
            $this->clickVisibleButton($browser, 'Erstellen');
            $browser->waitUsing(15, 100, static fn (): bool => App::query()->where('prefix', 'fahrzeuge')->exists());

            // Liste: Personal hat eine Tabelle und lässt sich nicht löschen, die neue App schon.
            $browser->visit('/admin/apps')->waitForText('Fahrzeuge', 15);
            $row = "//tr[contains(@class, 'fi-ta-row')][contains(., '%s')]//button[contains(., 'Löschen')]";
            $locked = $browser->driver->findElement(WebDriverBy::xpath(sprintf($row, 'Personal')));
            $this->assertTrue($locked->getAttribute('disabled') !== null || str_contains((string) $locked->getAttribute('class'), 'fi-disabled'), 'Löschen gesperrt.');
            $browser->clickAtXPath(sprintf($row, 'Fahrzeuge'))->waitFor('.fi-modal-window', 15);
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitUntilMissing('tr.fi-ta-row:nth-child(3)', 15);
            $this->assertFalse(App::query()->where('prefix', 'fahrzeuge')->exists());
        });
    }

    /** FAP-02 */
    public function test_table_gets_the_prefix_of_its_app_and_relations_offer_only_its_tables(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-designer/create')
                ->waitFor('select[id="form.app_id"]', 15)
                ->select('select[id="form.app_id"]', (string) $this->second->id)->pause(800)
                ->type('input[id="form.label.de"]', 'Abwesenheiten')
                ->keys('input[id="form.label.de"]', '{tab}')
                ->waitUsing(15, 100, fn (): bool => $browser->value('input[id="form.name"]') === 'hr_abwesenheiten')
                // Wechsel der App ersetzt das Präfix, zurück bringt es wieder.
                ->select('select[id="form.app_id"]', (string) $this->first->id)
                ->waitUsing(15, 100, fn (): bool => $browser->value('input[id="form.name"]') === 'abwesenheiten')
                ->select('select[id="form.app_id"]', (string) $this->second->id)
                ->waitUsing(15, 100, fn (): bool => $browser->value('input[id="form.name"]') === 'hr_abwesenheiten');

            // Verknüpfung: nur Tabellen der gewählten App.
            $browser->type('input[id^="form.fields."][id$=".label.de"]', 'Person')
                ->select('select[id^="form.fields."][id$=".type"]', 'relation')->pause(900)
                ->waitFor('select[id^="form.fields."][id$=".options.table"]', 15);
            $options = $browser->script("return Array.from(document.querySelector('select[id^=\"form.fields.\"][id$=\".options.table\"]').options).map(o => o.value).filter(v => v !== '');")[0];
            $this->assertSame(['hr_mitarbeitende'], $options);

            $browser->select('select[id^="form.fields."][id$=".type"]', 'string')->pause(700);
            $this->clickVisibleButton($browser, 'Erstellen');
            $browser->waitUsing(15, 100, static fn (): bool => CustomTable::query()->where('name', 'hr_abwesenheiten')->exists());
            $this->assertSame($this->second->id, CustomTable::query()->where('name', 'hr_abwesenheiten')->value('app_id'));
        });
    }

    /** FAP-03 */
    public function test_role_copy_into_another_app_and_matrix_shows_only_its_tables(): void
    {
        $role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $role->givePermissionTo(['data.kunden.view', 'reports.view']);

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, self::ADMIN)->visit('/admin/roles/'.$role->id.'/edit')->waitForText('Datenrechte', 15)
                ->assertSee('Kunden')
                ->assertDontSee('Mitarbeitende');

            $this->clickVisibleButton($browser, 'Kopieren');
            $browser->waitFor('.fi-modal-window input[id$="name"]', 15)
                ->clear('.fi-modal-window input[id$="name"]')
                ->type('.fi-modal-window input[id$="name"]', 'personal-lesen')
                ->select('.fi-modal-window select[id$="app_id"]', (string) $this->second->id)->pause(500);
            $this->clickVisibleButton($browser, 'Absenden');
            $browser->waitForText('Rolle „personal-lesen“ angelegt.', 15)
                ->waitForText('Datenrechte', 15)
                ->assertSee('Mitarbeitende')
                ->assertDontSee('Kunden');

            $copy = Role::findByName('personal-lesen');
            $this->assertSame($this->second->id, $copy->app_id);
            $this->assertSame(['reports.view'], $copy->permissions->pluck('name')->all());
        });
    }

    /** FAP-04 */
    public function test_connected_tables_are_moved_together_with_role_rights(): void
    {
        app(DynamicSchemaService::class)->createTable(['app_id' => $this->first->id, 'name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['firma']]],
        ]);
        $role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $role->givePermissionTo(['data.kunden.view', 'data.bestellungen.view']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-designer')->waitForText('Bestellungen', 15)
                ->click('tr.fi-ta-row input[type="checkbox"][value="'.CustomTable::query()->where('name', 'bestellungen')->value('id').'"]')
                ->pause(800);
            $this->clickVisibleButton($browser, 'In andere App verschieben');
            $browser->waitFor('.fi-modal-window select[id$="target"]', 15)
                ->select('.fi-modal-window select[id$="target"]', (string) $this->second->id)
                ->waitForText('So kann nicht verschoben werden', 15)
                ->assertSee('Verknüpfung „Kunde“ von Bestellungen zu Kunden');
            $this->clickVisibleButton($browser, 'Verknüpfte Tabellen hinzufügen');
            $browser->waitForText('Es werden 2 Tabellen', 15)
                ->assertDontSee('So kann nicht verschoben werden')
                ->assertInputValue('.fi-modal-window input[id$="role_names.'.Role::findByName('vertrieb')->id.'"]', 'vertrieb-hr');
            $this->clickVisibleButton($browser, 'Verschieben');
            $browser->waitForText('2 Tabellen nach „Personal“ verschoben.', 15);
        });

        $this->assertSame(2, CustomTable::query()->whereIn('name', ['kunden', 'bestellungen'])->where('app_id', $this->second->id)->count());
        $this->assertEqualsCanonicalizing(['data.kunden.view', 'data.bestellungen.view'], Role::findByName('vertrieb-hr')->permissions->pluck('name')->all());
    }

    /** FAP-05 */
    public function test_switcher_changes_navigation_and_a_link_switches_back(): void
    {
        app(DataMenu::class)->ensure(CustomTable::query()->where('name', 'kunden')->firstOrFail());
        app(DataMenu::class)->ensure(CustomTable::query()->where('name', 'hr_mitarbeitende')->firstOrFail());
        $sales = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $sales->givePermissionTo('data.kunden.view');
        $hr = Role::query()->create(['name' => 'personal', 'guard_name' => 'web', 'app_id' => $this->second->id]);
        $hr->givePermissionTo('data.hr_mitarbeitende.view');
        $this->activeUser('zwei@example.com')->assignRole([$sales, $hr, User::ROLE_DEFAULT]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'zwei@example.com')->visit('/admin')->waitFor('.saft-app-switcher', 15)
                ->assertSeeIn('.saft-app-switcher', $this->first->getTranslation('name', 'de'))
                ->assertSeeIn('.fi-sidebar', 'Kunden')
                ->assertDontSeeIn('.fi-sidebar', 'Mitarbeitende');

            $browser->click('.saft-app-switcher')->waitFor('[data-app-switch="'.$this->second->id.'"]', 15)
                ->click('[data-app-switch="'.$this->second->id.'"]')
                ->waitForTextIn('.saft-app-switcher', 'Personal', 15)
                ->assertSeeIn('.fi-sidebar', 'Mitarbeitende')
                ->assertDontSeeIn('.fi-sidebar', 'Kunden');

            // Lesezeichen auf eine Tabelle der anderen App: wechselt dorthin.
            $browser->visit('/admin/data/kunden')->waitForText('Firma', 15)
                ->assertSeeIn('.saft-app-switcher', $this->first->getTranslation('name', 'de'))
                ->assertSeeIn('.fi-sidebar', 'Kunden');
        });
    }

    /** FAP-06 */
    public function test_app_manual_appears_only_in_its_app(): void
    {
        AppManual::query()->create(['app_id' => $this->second->id, 'locale' => 'de', 'content' => "# Personal\n\n## Abwesenheiten erfassen\n\nSo geht es.\n"]);
        $tab = '[data-doc-tab="'.Documentation::APP_PREFIX.$this->second->id.'"]';

        $this->browse(function (Browser $browser) use ($tab): void {
            $this->login($browser, self::ADMIN)->visit('/admin/documentation')->waitForText('Anwenderhandbuch', 15)
                ->assertMissing($tab);

            $browser->click('.saft-app-switcher')->waitFor('[data-app-switch="'.$this->second->id.'"]', 15)
                ->click('[data-app-switch="'.$this->second->id.'"]')
                ->waitForTextIn('.saft-app-switcher', 'Personal', 15)
                ->visit('/admin/documentation')->waitFor($tab, 15)
                ->assertSeeIn($tab, 'Handbuch Personal')
                ->click($tab)
                ->waitForText('Abwesenheiten erfassen', 15);
        });
    }
}
