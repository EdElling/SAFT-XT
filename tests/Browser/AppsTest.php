<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Apps\Definition\AppDefinition;
use App\Documentation\Documentation;
use App\DynamicData\DataMenu;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\AppManual;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Spatie\Activitylog\Models\Activity;
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

            // Aktionsmenü: Personal hat eine Tabelle, „Löschen“ fehlt (dafür „Entfernen …“); die leere App lässt sich löschen.
            $browser->visit('/admin/apps')->waitForText('Fahrzeuge', 15);
            $this->openRowActions($browser, 'Personal');
            $this->assertSame([], array_filter($browser->elements('.fi-dropdown-panel button'), static fn ($b): bool => $b->isDisplayed() && trim($b->getText()) === 'Löschen'));
            // Neu laden schliesst das offene Menü (es läge sonst über dem nächsten Knopf).
            $browser->visit('/admin/apps')->waitForText('Fahrzeuge', 15);
            $this->openRowActions($browser, 'Fahrzeuge');
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitFor('.fi-modal-window', 15);
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
            $this->login($browser, self::ADMIN)->visit('/admin/roles/'.$role->id.'/edit?tab=data-rights')->waitForText('Datenrechte', 15)
                ->assertSee('Kunden')
                ->assertDontSee('Mitarbeitende');

            $this->clickVisibleButton($browser, 'Kopieren');
            $browser->waitFor('.fi-modal-window input[id$="name"]', 15)
                ->clear('.fi-modal-window input[id$="name"]')
                ->type('.fi-modal-window input[id$="name"]', 'personal-lesen')
                ->select('.fi-modal-window select[id$="app_id"]', (string) $this->second->id)->pause(500);
            $this->clickVisibleButton($browser, 'Absenden');
            $browser->waitForText('Rolle „personal-lesen“ angelegt.', 15)
                ->waitForText('Datenrechte', 15);
            // Die Kopie öffnet mit dem ersten Reiter (B-175).
            $browser->driver->findElement(WebDriverBy::xpath("//*[@role='tab'][normalize-space(.)='Datenrechte']"))->click();
            $browser->pause(300)
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

    /** Aktionsmenü (drei Punkte) in der Zeile einer App öffnen. */
    private function openRowActions(Browser $browser, string $app): Browser
    {
        return $browser->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., '{$app}')]//*[contains(@class, 'fi-dropdown-trigger')]//button")
            ->pause(400);
    }

    /** FAP-07 */
    public function test_deactivated_app_is_hidden_for_users_and_marked_for_the_super_admin(): void
    {
        app(DataMenu::class)->ensure(CustomTable::query()->where('name', 'kunden')->firstOrFail());
        $hr = Role::query()->create(['name' => 'personal', 'guard_name' => 'web', 'app_id' => $this->second->id]);
        $hr->givePermissionTo('data.hr_mitarbeitende.view');
        $sales = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $sales->givePermissionTo('data.kunden.view');
        $this->activeUser('zwei@example.com')->assignRole([$sales, $hr, User::ROLE_DEFAULT]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15);
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Deaktivieren');
            $browser->waitFor('.fi-modal-window', 15);
            $this->clickVisibleButton($browser, 'Bestätigen');
            $browser->waitForText('App „Personal“ deaktiviert.', 15)
                ->waitForTextIn('tr.fi-ta-row:nth-child(2)', 'Inaktiv', 15);
            $this->assertFalse($this->second->fresh()->is_active);

            // Super-Admin: im Umschalter weiterhin, als inaktiv markiert (Titelzeile nach dem Neuladen).
            $browser->refresh()->waitFor('.saft-app-switcher', 15)->click('.saft-app-switcher')->waitFor('[data-app-switch="'.$this->second->id.'"]', 15)
                ->assertSeeIn('[data-app-switch="'.$this->second->id.'"]', '(inaktiv)');
        });

        $this->browse(function (Browser $browser): void {
            $browser->logout();
            // Person mit Rollen in beiden Apps: nur noch eine App, also kein Umschalter; kein Zugriff auf die Tabelle.
            $this->login($browser, 'zwei@example.com')->visit('/admin')->waitFor('.fi-sidebar', 15)
                ->assertMissing('.saft-app-switcher')
                ->assertSeeIn('.fi-sidebar', 'Kunden')
                ->visit('/admin/data/hr_mitarbeitende')
                ->waitForText('403', 15);
        });
    }

    /** FAP-08 */
    public function test_removal_is_confirmed_twice_delayed_cancellable_and_then_runs(): void
    {
        $this->second->update(['is_active' => false]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15);
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Entfernen …');
            // 1. Abfrage: Übersicht und Häkchen.
            $browser->waitFor('[data-app-removal-overview]', 15)
                ->assertSeeIn('[data-app-removal-overview]', 'Tabelle „Mitarbeitende“ (hr_mitarbeitende) mit 0 Datensätzen')
                ->check('.fi-modal-window input[type="checkbox"][id$="understood"]');
            $this->clickVisibleButton($browser, 'Weiter');
            // 2. Abfrage: Name eintippen (falsch, dann richtig).
            $browser->waitFor('.fi-modal-window input[id$="confirmation"]', 15)
                ->type('.fi-modal-window input[id$="confirmation"]', 'Personalx');
            $this->clickVisibleButton($browser, 'Entfernen vormerken');
            $browser->waitForText('Der eingegebene Name stimmt nicht', 15)
                ->clear('.fi-modal-window input[id$="confirmation"]')
                ->type('.fi-modal-window input[id$="confirmation"]', 'Personal');
            $this->clickVisibleButton($browser, 'Entfernen vormerken');
            $browser->waitForText('Entfernen von „Personal“ vorgemerkt', 15)
                ->waitForTextIn('tr.fi-ta-row:nth-child(2)', 'Entfernen ab', 15);
            $this->assertTrue($this->second->fresh()->removalPending());

            // Abbrechen.
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Entfernen abbrechen');
            $browser->waitForText('Entfernen von „Personal“ abgebrochen.', 15);
            $this->assertFalse($this->second->fresh()->removalPending());

            // Erneut vormerken, Wartezeit abgelaufen (in der Datenbank vorgestellt): „Jetzt entfernen“.
            $this->second->forceFill(['removal_due_at' => now()->subMinute(), 'removal_requested_by' => User::query()->where('email', self::ADMIN)->value('id')])->save();
            $browser->visit('/admin/apps')->waitForText('Personal', 15);
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Jetzt entfernen');
            $browser->waitFor('.fi-modal-window', 15);
            $this->clickVisibleButton($browser, 'Bestätigen');
            $browser->waitForText('App „Personal“ entfernt.', 60);
        });

        $this->assertNull(App::query()->find($this->second->id));
        $this->assertNull(CustomTable::query()->where('name', 'hr_mitarbeitende')->first());
    }

    /** FAP-09 */
    public function test_role_shows_permissions_outside_its_app_as_matrix_for_removal(): void
    {
        $role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $role->givePermissionTo(['data.kunden.view', 'data.hr_mitarbeitende.view', 'data.hr_mitarbeitende.update']);

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, self::ADMIN)->visit('/admin/roles/'.$role->id.'/edit?tab=outside')->waitForText('Rechte ausserhalb dieser App', 15)
                ->assertPresent('[data-matrix-cell="hr_mitarbeitende.view"]')
                ->assertPresent('[data-matrix-cell="hr_mitarbeitende.update"]')
                ->assertMissing('[data-matrix-cell="hr_mitarbeitende.create"]')
                ->assertChecked('[data-matrix-cell="hr_mitarbeitende.update"]')
                ->uncheck('[data-matrix-cell="hr_mitarbeitende.update"]')->pause(400);
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->waitForText('Gespeichert', 15);
        });

        $this->assertEqualsCanonicalizing(['data.kunden.view', 'data.hr_mitarbeitende.view'], $role->fresh()->permissions->pluck('name')->all());
    }

    /** FAP-10 */
    public function test_export_dialog_and_data_model_of_an_app(): void
    {
        app(DynamicSchemaService::class)->createTable(['app_id' => $this->second->id, 'name' => 'hr_abwesenheiten', 'label' => 'Abwesenheiten'], [
            ['name' => 'person_id', 'label' => 'Person', 'type' => 'relation', 'options' => ['table' => 'hr_mitarbeitende', 'display_fields' => ['name']]],
        ]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15);

            // Datenmodell: Diagramm mit den Tabellen der App.
            $this->openRowActions($browser, 'Personal');
            $browser->clickLink('Datenmodell')
                ->waitForText('Datenmodell: Personal', 15)
                ->waitFor('.saft-data-model svg', 20)
                ->assertSeeIn('.saft-data-model', 'Mitarbeitende')
                ->assertSeeIn('.saft-data-model', 'Abwesenheiten')
                ->assertDontSeeIn('.saft-data-model', 'Kunden');

            // Export: Dialog mit den wählbaren Teilen; Herunterladen ohne Fehlermeldung.
            $browser->visit('/admin/apps')->waitForText('Personal', 15);
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Exportieren …');
            $browser->waitForText('App „Personal“ exportieren', 15)
                ->assertSee('Rollen mit Rechten')
                ->assertSee('Handbuch');
            $this->clickVisibleButton($browser, 'Herunterladen');
            $browser->pause(1500)->assertDontSee('nicht stimmig');
        });

        $this->assertSame(1, Activity::query()->where('event', 'exported')->where('subject_id', $this->second->id)->count());
    }

    /** FAP-11 */
    public function test_app_is_installed_as_a_copy_from_an_exported_file(): void
    {
        $definition = app(AppDefinition::class)->build($this->second->fresh());
        $path = storage_path('framework/testing/hr.saft-app.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($definition));

        $this->browse(function (Browser $browser) use ($path): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15);
            $this->clickVisibleButton($browser, 'App installieren …');
            $browser->waitFor('.fi-modal-window .filepond--root', 15)
                ->attach('.fi-modal-window .filepond--root input[type="file"]', $path)
                ->waitUntil("document.querySelectorAll('.filepond--item[data-filepond-item-state=\"idle\"], .filepond--item[data-filepond-item-state=\"processing-complete\"]').length >= 1", 30);
            $this->clickVisibleButton($browser, 'Weiter');

            // Vorschau: gleiche App schon installiert – als Kopie mit vorgeschlagenem Namen und Präfix.
            $browser->waitFor('[data-app-install-preview]', 15)
                ->assertSeeIn('[data-app-install-blockers]', 'bereits installiert')
                ->assertSeeIn('[data-app-install-counts]', '1 Tabelle')
                ->waitFor('.fi-modal-window .saft-data-model svg', 20)
                ->clickAtXPath("//label[contains(., 'Kopie (neuer Name und Präfix)')]")
                ->waitFor('.fi-modal-window input[id$="copy_prefix"]', 15)
                ->assertInputValue('.fi-modal-window input[id$="copy_prefix"]', 'hr2')
                ->waitUntilMissing('[data-app-install-blockers]', 15);
            $this->clickVisibleButton($browser, 'Installieren');
            $browser->waitForText('App „Personal (Kopie)“ installiert (deaktiviert).', 30);
        });

        $copy = App::query()->where('prefix', 'hr2')->sole();
        $this->assertFalse($copy->is_active);
        $this->assertSame(['hr2_mitarbeitende'], $copy->customTables()->pluck('name')->all());
        File::delete($path);
    }

    /** FAP-13 */
    public function test_table_is_shared_and_linked_from_another_app(): void
    {
        $staff = CustomTable::query()->where('name', 'hr_mitarbeitende')->sole();

        $this->browse(function (Browser $browser) use ($staff): void {
            // Freigeben im Table-Designer: Schalter an, vorgeschlagene Felder, speichern.
            $this->login($browser, self::ADMIN)->visit('/admin/table-designer/'.$staff->id.'/edit')->waitForText('Schnittstelle', 15)
                ->clickAtXPath("//*[contains(@class, 'fi-section')][.//*[contains(., 'Schnittstelle')]]//*[contains(@class, 'fi-section-header')]")
                ->waitForText('Für andere Apps freigeben', 15)
                ->clickAtXPath("//label[contains(., 'Für andere Apps freigeben')]//button | //*[contains(., 'Für andere Apps freigeben')]/ancestor::*[contains(@class,'fi-fo-field')][1]//button[@role='switch']")
                ->waitForText('Freigegebene Felder', 15)
                ->clickAtXPath("//label[contains(., 'Name (name)')]//input[@type='checkbox']")
                ->pause(400);
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->waitForText('Gespeichert', 15);
        });

        $staff->refresh();
        $this->assertTrue($staff->is_shared);
        $this->assertSame(['name'], $staff->fields()->where('is_shared', true)->pluck('name')->all());

        // Verknüpfung aus der anderen App; danach ist die Freigabe gesperrt.
        app(DynamicSchemaService::class)->addField(CustomTable::query()->where('name', 'kunden')->sole(), ['name' => 'betreuer_id', 'label' => 'Betreuer', 'type' => 'relation', 'options' => ['table' => 'hr_mitarbeitende', 'display_fields' => ['name']]]);

        $this->browse(function (Browser $browser) use ($staff): void {
            $browser->visit('/admin/table-designer/'.$staff->id.'/edit')->waitForText('Schnittstelle', 15)
                ->waitForText('Fest freigegeben, weil von anderen Apps genutzt: Name (name)', 15)
                ->assertSee('kunden.betreuer_id');
        });
    }

    /** FAP-14 */
    public function test_report_reads_shared_fields_of_another_app_without_its_permission(): void
    {
        $schema = app(DynamicSchemaService::class);
        $staff = CustomTable::query()->where('name', 'hr_mitarbeitende')->sole();
        $schema->updateInterface($staff, true, ['name']);
        $relation = $schema->addField(CustomTable::query()->where('name', 'kunden')->sole(), ['name' => 'betreuer_id', 'label' => 'Betreuer', 'type' => 'relation', 'options' => ['table' => 'hr_mitarbeitende', 'display_fields' => ['name']]]);
        $anna = DynamicRecord::forTableName('hr_mitarbeitende')->create(['name' => 'Anna Muster']);
        DynamicRecord::forTableName('kunden')->create(['firma' => 'Beispiel AG', 'betreuer_id' => $anna->id]);
        $customers = CustomTable::query()->where('name', 'kunden')->with('fields')->sole();
        $report = Report::query()->create(['name' => 'Betreuung', 'app_id' => $this->first->id, 'owner_id' => User::query()->where('email', self::ADMIN)->value('id'), 'is_shared' => true, 'definition' => [
            'base' => $customers->id,
            'fields' => [
                ['key' => 'firma', 'path' => [], 'field' => $customers->fields->firstWhere('name', 'firma')->id],
                ['key' => 'betreuer', 'path' => [$relation->id], 'field' => $staff->fields()->where('name', 'name')->value('id'), 'label' => 'Betreuer Name'],
            ],
        ]]);

        // Person der ersten App: nur Kunden lesbar, nicht die Mitarbeitenden – der freigegebene Name erscheint trotzdem.
        $role = Role::query()->create(['name' => 'vertrieb', 'guard_name' => 'web', 'app_id' => $this->first->id]);
        $role->givePermissionTo('reports.view', 'data.kunden.view');
        $this->activeUser('vertrieb@example.com')->assignRole($role);

        $this->browse(function (Browser $browser) use ($report): void {
            $this->login($browser, 'vertrieb@example.com')->visit('/admin/reports/'.$report->id)
                ->waitForText('Beispiel AG', 15)
                ->assertSee('Anna Muster');
            $this->logout($browser);

            // Die Schnittstelle nennt die Auswertung als Nutzer.
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15);
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Schnittstellen');
            $browser->waitFor('.fi-modal-window', 15)
                ->waitForText('Auswertung „Betreuung“', 15);
        });
    }

    /** FAP-16 */
    public function test_app_is_updated_from_a_newer_package_with_a_change_plan(): void
    {
        // Ausgangsstand wie nach dem Installieren; das Paket bringt ein neues Feld und eine neue Bezeichnung.
        $admin = User::query()->where('email', self::ADMIN)->sole();
        $definition = app(AppDefinition::class)->build($this->second->fresh(), AppDefinition::PARTS, $admin);
        AppBaseline::query()->create(['app_id' => $this->second->id, 'kind' => AppBaseline::INSTALLED, 'version' => '1.0', 'definition' => $definition, 'installed_by' => $admin->id]);
        $definition['app']['version'] = '1.1';
        $definition['tables'][0]['fields'][0]['label'] = ['de' => 'Vor- und Nachname'];
        $definition['tables'][0]['fields'][] = ['key' => (string) Str::uuid(), 'name' => 'abteilung', 'label' => ['de' => 'Abteilung'], 'type' => 'string', 'sort_order' => 9];
        $path = storage_path('framework/testing/hr-1.1.saft-app.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($definition));

        $this->browse(function (Browser $browser) use ($path): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15);
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Aktualisieren …');
            $browser->waitFor('.fi-modal-window .filepond--root', 15)
                ->attach('.fi-modal-window .filepond--root input[type="file"]', $path)
                ->waitUntil("document.querySelectorAll('.filepond--item[data-filepond-item-state=\"idle\"], .filepond--item[data-filepond-item-state=\"processing-complete\"]').length >= 1", 30);
            $this->clickVisibleButton($browser, 'Weiter');

            // Plan: zwei sichere, vorgewählte Schritte.
            $browser->waitFor('[data-app-update-plan]', 15)
                ->assertSeeIn('[data-app-update-versions]', 'Paket: 1.1')
                ->waitForText('Feld · Mitarbeitende › abteilung: neu', 15)
                ->assertSee('Bezeichnung Name → Vor- und Nachname');
            $this->assertCount(2, $browser->elements('.fi-modal-window input[type="checkbox"]:checked'));
            $this->clickVisibleButton($browser, 'Gewählte Schritte ausführen');
            $browser->waitForText('App „Personal“ aktualisiert', 60);
        });

        $this->assertSame('1.1', $this->second->fresh()->version);
        $this->assertTrue(CustomTable::query()->where('name', 'hr_mitarbeitende')->sole()->fields()->where('name', 'abteilung')->exists());
        File::delete($path);
    }

    /** FAP-18 */
    public function test_update_also_changes_reports_menus_and_roles(): void
    {
        $admin = User::query()->where('email', self::ADMIN)->sole();
        $staff = CustomTable::query()->where('name', 'hr_mitarbeitende')->with('fields')->sole();
        Report::query()->create(['name' => 'Personalliste', 'app_id' => $this->second->id, 'owner_id' => $admin->id, 'is_shared' => true,
            'definition' => ['base' => $staff->id, 'fields' => [['key' => 'n', 'path' => [], 'field' => $staff->fields->firstWhere('name', 'name')->id]]]]);
        $role = Role::query()->create(['name' => 'personal', 'guard_name' => 'web', 'app_id' => $this->second->id]);
        $role->givePermissionTo('data.hr_mitarbeitende.view');
        $definition = app(AppDefinition::class)->build($this->second->fresh(), AppDefinition::PARTS, $admin);
        AppBaseline::query()->create(['app_id' => $this->second->id, 'kind' => AppBaseline::INSTALLED, 'version' => '1.0', 'definition' => $definition, 'installed_by' => $admin->id]);
        $definition['app']['version'] = '1.1';
        $definition['reports'][0]['name'] = 'Mitarbeitende nach Name';
        $definition['roles'][0]['permissions'] = ['data.hr_mitarbeitende.update', 'data.hr_mitarbeitende.view'];
        $path = storage_path('framework/testing/hr-1.1b.saft-app.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($definition));

        $this->browse(function (Browser $browser) use ($path): void {
            $this->login($browser, self::ADMIN)->visit('/admin/apps')->waitForText('Personal', 15);
            $this->openRowActions($browser, 'Personal');
            $this->clickVisibleButton($browser, 'Aktualisieren …');
            $browser->waitFor('.fi-modal-window .filepond--root', 15)
                ->attach('.fi-modal-window .filepond--root input[type="file"]', $path)
                ->waitUntil("document.querySelectorAll('.filepond--item[data-filepond-item-state=\"idle\"], .filepond--item[data-filepond-item-state=\"processing-complete\"]').length >= 1", 30);
            $this->clickVisibleButton($browser, 'Weiter');
            $browser->waitFor('[data-app-update-plan]', 15)
                ->waitForText('Name Personalliste → Mitarbeitende nach Name', 15)
                ->assertSee('Rechte: + data.hr_mitarbeitende.update');
            $this->clickVisibleButton($browser, 'Gewählte Schritte ausführen');
            $browser->waitForText('App „Personal“ aktualisiert', 60);
        });

        $this->assertTrue(Report::query()->where('name', 'Mitarbeitende nach Name')->exists());
        $this->assertTrue($role->fresh()->hasPermissionTo('data.hr_mitarbeitende.update'));
        File::delete($path);
    }
}
