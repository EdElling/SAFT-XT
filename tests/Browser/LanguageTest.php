<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\ExternalData\ConnectionDriver;
use App\ExternalData\TableConnectorService;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Services\DynamicSchemaService;
use App\Settings\AuthSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\DuskTestCase;

/** Sprachwahl der Oberfläche (Bereich SPR). */
class LanguageTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    protected function externalDatabaseName(): string
    {
        return self::EXTERNAL_DATABASE;
    }

    private const string ADMIN = 'admin@example.com';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->superAdmin(self::ADMIN);
    }

    private function switchTo(Browser $browser, string $label): Browser
    {
        $this->clickVisible($browser, '.fi-ls .fi-dropdown-trigger');
        $browser->pause(300);
        $browser->clickAtXPath("//button[contains(@class, 'fi-ls-item')][contains(., '{$label}')]");

        return $browser->pause(800);
    }

    /** SPR-01 */
    public function test_language_can_be_chosen_on_the_login_page_and_survives_logout(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/login')->waitFor(self::SELECTOR_EMAIL, 15)->assertSee('Anmelden');

            $this->switchTo($browser, 'English');
            $browser->waitForText('Sign in', 15)->assertSourceHas('lang="en"');

            // Anmelden, abmelden: Die Anmeldeseite bleibt englisch, obwohl der Browser Deutsch meldet.
            $this->submitLogin($browser, self::ADMIN)->waitFor('.fi-user-menu-trigger', 15);
            $browser->click('.fi-user-menu-trigger')->waitForText('Sign out')->press('Sign out')->waitFor(self::SELECTOR_EMAIL, 15);
            $browser->assertSourceHas('lang="en"')->assertSee('Sign in');
        });
    }

    /** SPR-02 */
    public function test_choice_inside_the_panel_is_stored_and_applies_at_once(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitFor('.fi-ta-table', 15);
            $browser->assertSourceHas('lang="de"');

            $this->switchTo($browser, 'English');
            $browser->waitUsing(15, 100, fn (): bool => str_contains($browser->driver->getPageSource(), 'lang="en"'))
                ->assertPathIs('/admin/users');

            $this->assertSame('en', $this->admin->fresh()->locale);

            $browser->visit('/admin/users')->waitFor('.fi-ta-table', 15)->assertSourceHas('lang="en"');
        });
    }

    /** SPR-03 */
    public function test_setting_of_the_user_follows_to_another_device(): void
    {
        $this->admin->forceFill(['locale' => 'en'])->save();

        $this->browse(function (Browser $browser): void {
            // Neue Sitzung ohne Sprach-Cookie und mit deutschem Browser.
            $this->login($browser, self::ADMIN);
            $browser->assertSourceHas('lang="en"');

            $this->switchTo($browser, 'Deutsch');
            $browser->waitUsing(15, 100, fn (): bool => str_contains($browser->driver->getPageSource(), 'lang="de"'));
            $this->assertSame('de', $this->admin->fresh()->locale);
        });
    }

    /** SPR-04 */
    public function test_livewire_requests_keep_the_language(): void
    {
        $this->admin->forceFill(['locale' => 'en'])->save();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users/create')->waitFor('input[id="form.name"]', 15);
            $this->disableNativeValidation($browser);

            // Die Meldung entsteht in einer Livewire-Anfrage auf dem Server.
            $browser->press('Create')->waitForText('field is required', 15)->assertDontSee('muss ausgefüllt');
        });
    }

    /** SPR-05 */
    public function test_dates_and_numbers_follow_the_language_in_list_and_popup(): void
    {
        $table = app(DynamicSchemaService::class)->createTable(['name' => 'vertraege', 'label' => 'Verträge'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'start', 'label' => 'Start', 'type' => 'date'],
            ['name' => 'zeit', 'label' => 'Zeit', 'type' => 'datetime'],
        ]);
        $record = DynamicRecord::forTable($table)->create(['titel' => 'Vertrag', 'betrag' => '1234.50', 'start' => '2026-02-01', 'zeit' => '2026-02-01 14:30:00']);
        $this->assertNotNull($record->getKey());

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/vertraege')->waitForText('Vertrag', 15);
            $browser->assertSee('1.234,50')->assertSee('01.02.2026')->assertSee('01.02.2026 14:30');

            $this->switchTo($browser, 'English');
            $browser->waitForText('1,234.50', 15)->assertSee('01/02/2026')->assertSee('01/02/2026 14:30');

            $this->openDetails($browser, 'Vertrag');
            $browser->waitForText('01/02/2026 14:30', 15)->assertSee('1,234.50');
        });
    }

    /** SPR-06 */
    public function test_switch_is_on_the_password_reset_page_too(): void
    {
        $settings = app(AuthSettings::class);
        $settings->password_reset_enabled = true;
        $settings->save();

        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/password-reset/request')->waitFor(self::SELECTOR_EMAIL, 15);

            $this->switchTo($browser, 'English');
            $browser->waitUsing(15, 100, fn (): bool => str_contains($browser->driver->getPageSource(), 'lang="en"'))
                ->assertPathIs('/admin/password-reset/request');
        });
    }

    /**
     * Deutsche Wörter, die in den anderen Sprachen nicht vorkommen dürfen, samt Seitenleiste mit den
     * mitgelieferten Menüeinträgen. Ohne „Tabelle“: Das ist auch der italienische Plural von „tabella“.
     */
    private const string GERMAN = '/[äöüÄÖÜß]|\b(und|oder|der|die|das|nicht|nur|mit|für|bei|wird|werden|Sie|Ihre?|Keine?|Neue?r?|Feld|Felder|Datensatz|Datensätze|Benutzer|Rolle|Rollen|Rechte|Anmeld\w*|Abmeld\w*|Speichern|Bearbeiten|Anzeigen|Aktiv|Bezeichnung|Beschreibung|Sortierung|Eintrag|Seite|Zeitpunkt|Ereignis|Verlauf|Schließen|Exportieren|Typ|Wert|Werte|Ja|Nein|Gruppe|Zuordnung\w*|Verknüpf\w*|Einstellungen|Allgemein|Optionen|Erstellen|Abbrechen)\b/u';

    /**
     * @return list<string>
     */
    private function germanWords(Browser $browser): array
    {
        $text = (string) $browser->script(<<<'JS'
            const main = document.querySelector('.fi-main') ?? document.body;
            const sidebar = document.querySelector('.fi-sidebar');
            const modals = [...document.querySelectorAll('.fi-modal-window')].filter(m => m.offsetParent !== null);
            return [main, sidebar, ...modals].filter(Boolean).map(e => e.innerText).join('\n');
        JS)[0];

        preg_match_all(self::GERMAN, $text, $matches, PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $m): string => '„'.$m[0].'“ in: '.trim((string) preg_replace('/\s+/', ' ', mb_strcut($text, max(0, $m[1] - 40), 100))),
            $matches[0],
        );
    }

    /** SPR-09: Englisch, Französisch und Italienisch */
    public function test_other_languages_have_no_german_texts(): void
    {
        // Das Protokoll der Grundausstattung enthält deutsche Werte (Daten, keine Oberfläche).
        DB::table('activity_log')->delete();

        $schema = app(DynamicSchemaService::class);
        $customers = $schema->createTable(['name' => 'customers', 'label' => 'Customers', 'export_enabled' => true], [
            ['name' => 'company', 'label' => 'Company', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'rating', 'label' => 'Rating', 'type' => 'select', 'options' => ['options' => [['value' => 'a', 'label' => 'Good']]]],
            ['name' => 'since', 'label' => 'Since', 'type' => 'date'],
        ]);
        $orders = $schema->createTable(['name' => 'orders', 'label' => 'Orders'], [
            ['name' => 'number', 'label' => 'Number', 'type' => 'string', 'is_required' => true],
            ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'relation', 'options' => ['table' => 'customers', 'display_field' => 'company']],
        ]);
        $schema->updateTable($customers, ['detail_field_ids' => [(int) $orders->fields()->where('name', 'customer_id')->value('id')]]);
        $acme = DynamicRecord::forTable($customers)->create(['company' => 'Acme Ltd', 'rating' => 'a', 'since' => '2026-02-01']);
        DynamicRecord::forTable($orders)->create(['number' => 'O-1', 'customer_id' => $acme->id]);
        $acme->update(['company' => 'Acme Limited']);
        $connection = DatabaseConnection::create([
            'name' => 'ERP', 'driver' => ConnectionDriver::Pgsql, 'host' => 'db.example.com', 'port' => 5432,
            'database' => 'erp', 'username' => 'saft', 'password' => 'x',
        ]);
        // Angebundene Tabelle mit Spaltennamen ohne deutsche Signalwörter (Daten, keine Oberfläche).
        $this->prepareExternalDatabase();
        $linked = app(TableConnectorService::class)->connect($this->researchConnection(), 'public', 'importprotokoll', ['name' => 'import_log', 'label' => ['de' => 'Import log']]);
        app(TableConnectorService::class)->updateField($linked->fields->firstWhere('name', 'zeitpunkt'), ['label' => ['de' => 'Timestamp']]);
        DB::table('activity_log')->delete();

        $pages = [
            '/admin', '/admin/users', '/admin/users/create', "/admin/users/{$this->admin->id}/edit",
            '/admin/roles', '/admin/roles/create', '/admin/menus', '/admin/menus/create',
            '/admin/table-designer', '/admin/table-designer/create', "/admin/table-designer/{$customers->id}/edit",
            '/admin/identity-providers', '/admin/identity-providers/create', '/admin/auth-settings', '/admin/app-settings',
            '/admin/activity-log', '/admin/activity-log/'.Activity::query()->where('event', 'updated')->latest('id')->value('id'),
            '/admin/database-connections', '/admin/database-connections/create', "/admin/database-connections/{$connection->id}/edit",
            '/admin/table-connector', '/admin/table-connector/create', "/admin/table-connector/{$linked->id}/edit",
            "/admin/data/customers?sel={$acme->id}",
        ];

        $this->browse(function (Browser $browser) use ($pages): void {
            $this->login($browser, self::ADMIN);
            $findings = [];

            foreach (['en', 'fr', 'it'] as $locale) {
                // Die Einstellung am Konto gilt ab der nächsten Anfrage.
                $this->admin->forceFill(['locale' => $locale])->save();
                $findings = [...$findings, ...$this->scanPages($browser, $pages, $locale)];
            }

            $this->assertSame([], array_values(array_unique($findings)));
        });
    }

    /**
     * @param  list<string>  $pages
     * @return list<string>
     */
    private function scanPages(Browser $browser, array $pages, string $locale): array
    {
        $findings = [];

        foreach ($pages as $page) {
            $browser->visit($page)->waitFor('.fi-main', 15)->pause(1500);
            $this->scrollThroughPage($browser);

            foreach ($this->germanWords($browser) as $finding) {
                $findings[] = "{$locale} {$page}: {$finding}";
            }
        }

        // SAML-Formular und Popups zusätzlich
        $browser->visit('/admin/identity-providers/create')->waitFor('.fi-main', 15)->pause(800);
        $browser->clickAtXPath("//label[contains(., 'SAML')]")->pause(1200);
        foreach ($this->germanWords($browser) as $finding) {
            $findings[] = "{$locale} SAML: {$finding}";
        }

        $browser->visit('/admin/data/customers')->waitForText('Acme Limited', 15);
        $this->openDetails($browser, 'Acme Limited', __('data.actions.details', [], $locale));
        foreach ($this->germanWords($browser) as $finding) {
            $findings[] = "{$locale} Details: {$finding}";
        }
        $this->clickVisibleButton($browser, __('data.history.label', [], $locale))->pause(1200);
        foreach ($this->germanWords($browser) as $finding) {
            $findings[] = "{$locale} History: {$finding}";
        }

        return $findings;
    }

    /** SPR-10 */
    public function test_menu_entry_has_a_label_per_language(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/menus/create')->waitFor('input[id="form.label.de"]', 15)
                ->type('input[id="form.label.de"]', 'Berichte')
                ->type('input[id="form.label.en"]', 'Reports');
            $browser->clickAtXPath("//label[contains(., 'Externe URL')]")->waitFor('input[id="form.url"]', 15)
                ->type('input[id="form.url"]', 'https://example.com')
                ->press('Erstellen')
                ->waitForText('Erstellt', 15);

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Berichte')
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Benutzer');

            $this->switchTo($browser, 'English');
            $browser->waitUsing(15, 100, fn (): bool => str_contains($browser->text(self::SELECTOR_SIDEBAR), 'Reports'))
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Users')
                ->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Berichte');
        });
    }

    /** SPR-11 */
    public function test_table_and_field_names_follow_the_language(): void
    {
        $schema = app(DynamicSchemaService::class);
        $table = $schema->createTable(['name' => 'vertraege', 'label' => ['de' => 'Verträge', 'en' => 'Contracts']], [
            ['name' => 'titel', 'label' => ['de' => 'Titel', 'en' => 'Title'], 'type' => 'string', 'is_required' => true],
            ['name' => 'status', 'label' => ['de' => 'Zustand'], 'type' => 'select', 'options' => ['options' => [['value' => 'o', 'label' => ['de' => 'Offen', 'en' => 'Open']]]]],
        ]);
        app(DataMenu::class)->ensure($table);
        DynamicRecord::forTable($table)->create(['titel' => 'Rahmen', 'status' => 'o']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/vertraege')->waitForText('Rahmen', 15)
                ->assertSee('Verträge')->assertSee('Titel')->assertSee('Offen');

            $this->switchTo($browser, 'English');
            $browser->waitForText('Contracts', 15)
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Contracts')
                ->assertSee('Title')
                ->assertSee('Open')
                // Ohne englische Bezeichnung erscheint die deutsche.
                ->assertSee('Zustand');
        });
    }
}
