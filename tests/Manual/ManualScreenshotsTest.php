<?php

declare(strict_types=1);

namespace Tests\Manual;

use App\Auth\AuthMode;
use App\Auth\Sso\IdentityProviderType;
use App\Auth\Sso\Saml\Certificates;
use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\ExternalData\ConnectionDriver;
use App\ExternalData\ConnectionTester;
use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\IdentityProvider;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Settings\AppSettings;
use App\Support\ColorScheme;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ExternalResearchDatabase;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ConfiguresAuth;
use Tests\DuskTestCase;

/**
 * Erzeugt die Screenshots des Benutzerhandbuchs in docs/manual/images/<sprache>/.
 *
 * Kein Test im eigentlichen Sinn und nicht Teil von composer test:browser:
 *   composer manual:screenshots
 * Läuft wie alle Browser-Tests gegen SAFT2_dusk und leert diese Datenbank (siehe DuskTestCase).
 * Die roten Markierungen entsprechen den Nummern im Handbuchtext.
 */
final class ManualScreenshotsTest extends DuskTestCase
{
    use ConfiguresAuth;

    private const string ADMIN = 'admin@example.com';

    private const int WIDTH = 1400;

    private const int HEIGHT = 860;

    private const string ROW = "//tr[contains(@class, 'fi-ta-row')]";

    private string $locale = 'en';

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return ['en' => ['en'], 'de' => ['de'], 'fr' => ['fr'], 'it' => ['it']];
    }

    protected function browserLanguage(): string
    {
        return $this->locale;
    }

    #[DataProvider('locales')]
    public function test_01_login(string $locale): void
    {
        $this->useLocale($locale);
        $this->setAuthMode(AuthMode::Hybrid, passwordReset: true);
        $this->entraProvider(['name' => $this->text('Company login', 'Firmen-Login', 'Connexion entreprise', 'Accesso aziendale')]);
        $this->samlProvider();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, 760);
            $browser->visit('/admin/login')->waitFor(self::SELECTOR_EMAIL, 15)->pause(500);

            $this->capture($browser, '01-login', [
                ["//*[contains(@class, 'fi-btn')][contains(., '".$this->text('Company login', 'Firmen-Login', 'Connexion entreprise', 'Accesso aziendale')."')]/..", 1],
                ["//form[.//input[@id='form.email']]//*[contains(@class, 'fi-fo-field')][.//input[@id='form.email']]", 2],
                ["//a[contains(@href, 'password-reset')]", 3, 'right'],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_02_dashboard(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData();
        $this->setAuthMode(AuthMode::Hybrid);
        $this->markTested($this->entraProvider(['name' => $this->text('Company login', 'Firmen-Login', 'Connexion entreprise', 'Accesso aziendale')]));
        $admin = $this->admin();
        // Frühere Anmeldung, damit „Meine letzte Anmeldung“ einen Zeitpunkt zeigt.
        activity('auth')->performedOn($admin)->causedBy($admin)->event('login')
            ->withProperties(['method' => 'password', 'ip' => '192.0.2.10'])
            ->createdAt(now()->subDay()->setTime(8, 12))
            ->log('login');

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, 1000);
            $this->login($browser, self::ADMIN);
            $this->scrollThroughPage($browser);
            $browser->script('window.scrollTo(0, 0);');
            $browser->pause(800);

            $this->capture($browser, '02-dashboard');
        });
    }

    #[DataProvider('locales')]
    public function test_03_data_table(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData(export: true);
        $this->compactCustomers();
        $this->admin();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, self::HEIGHT);
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitFor('tr.fi-ta-row', 15)->pause(500);

            $this->capture($browser, '03-data-table', [
                ["//*[contains(@class, 'fi-ta-search-field')]", 1],
                ["(//*[contains(@class, 'fi-ta-filters-dropdown')]//button)[1]", 2, 'right'],
                ["//th[contains(@class, 'fi-ta-header-cell-firma')]", 3],
                ['('.self::ROW."//button[contains(@*[name()='wire:click'], \"'details'\")])[1]", 4],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_04_cascade(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData();
        $this->compactCustomers();
        $this->admin();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, 1950);
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitFor('tr.fi-ta-row', 15)->pause(500);
            $browser->clickAtXPath('('.self::ROW.")[1]//td[contains(@class, 'fi-ta-cell')][1]")->pause(1500);
            $browser->clickAtXPath('(//table)[2]'.self::ROW."[1]//td[contains(@class, 'fi-ta-cell')][1]")->pause(1500);
            // Ab der ausgewählten Zeile, damit alle vier Ebenen ins Bild passen.
            $this->scrollTo($browser, "//tr[contains(@class, 'saft-row-selected')][1]", 150);

            $this->capture($browser, '04-cascade', [
                ["//tr[contains(@class, 'saft-row-selected')][1]", 1],
                ["(//table)[1]/following::*[contains(concat(' ', @class, ' '), ' fi-section ')][1]", 2],
                ['(//table)[2]', 3],
                ['(//table)[3]', 4],
                ["(//table)[3]//td[contains(@class, 'artikel-id')][1]//*[self::a or self::button]", 5, 'right'],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_05_details_popup(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData();
        $this->admin();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, self::HEIGHT);
            $this->login($browser, self::ADMIN)->visit('/admin/data/bestellungen')->waitFor('tr.fi-ta-row', 15)->pause(500);
            $browser->clickAtXPath('('.self::ROW."//button[contains(@*[name()='wire:click'], \"'details'\")])[1]")
                ->waitFor('.fi-modal-window .fi-modal-footer', 15)->pause(600);

            $this->capture($browser, '05-details-popup', [
                ["//*[contains(@class, 'fi-modal-window')]//*[contains(@class, 'fi-modal-footer')]/*[1]", 1],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_06_preview(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData();
        $this->admin();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, self::HEIGHT);
            $this->login($browser, self::ADMIN)->visit('/admin/data/bestellpositionen')->waitFor('tr.fi-ta-row', 15)->pause(500);
            $browser->clickAtXPath("(//td[contains(@class, 'artikel-id')]//*[self::a or self::button])[1]")
                ->waitFor('.fi-modal-window .fi-modal-footer', 15)->pause(600);

            $this->capture($browser, '06-preview', [
                ["//*[contains(@class, 'fi-modal-window')]//*[contains(@class, 'fi-modal-footer')]//*[self::a or self::button][1]", 1],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_07_roles(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData();
        $this->admin();
        // Rolle der App der Demo-Tabellen (B-107), sonst zeigt der Reiter nur den Hinweis „Zuerst die App wählen“.
        $role = Role::create(['name' => $this->text('sales', 'vertrieb', 'vente', 'vendite'), 'guard_name' => 'web', 'app_id' => CustomTable::query()->where('name', 'kunden')->value('app_id')]);
        $role->givePermissionTo([
            ...array_map(static fn (string $action): string => DataPermissions::name('kunden', $action), ['view', 'create', 'update', 'export']),
            ...array_map(static fn (string $action): string => DataPermissions::name('bestellungen', $action), ['view', 'create', 'update', 'delete']),
            ...array_map(static fn (string $action): string => DataPermissions::name('bestellpositionen', $action), ['view', 'create', 'update', 'delete']),
            DataPermissions::name('artikel', 'view'),
        ]);
        // Der Vertrieb braucht Artikel nur in der Vorschau, nicht im Menü.
        $role->hiddenMenus()->sync(Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'artikel')->pluck('id')->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->browse(function (Browser $browser) use ($role): void {
            $this->viewport($browser, self::WIDTH, 1200);
            // Reiter „Datenrechte“ (B-175) über die Adresse; Name und App bleiben oben im Bild.
            $this->login($browser, self::ADMIN)->visit("/admin/roles/{$role->id}/edit?tab=data-rights")
                ->waitFor('input[data-matrix-cell="kunden.view"]', 15)->pause(500);

            $this->capture($browser, '07-roles-data-permissions');
        });
    }

    #[DataProvider('locales')]
    public function test_08_menu_data_table(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData();
        $this->admin();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, 1000);
            $this->login($browser, self::ADMIN)->visit('/admin/menus/create')->waitFor('input[id="form.label.de"]', 15);
            $browser->clickAtXPath("//label[contains(., '".__('menus.types.data')."')]")->pause(700);
            $this->pickOption($browser, 'form.data_table', CustomTable::query()->where('name', 'kunden')->firstOrFail()->label);
            $browser->pause(800);
            $this->scrollTo($browser, "//label[contains(., '".__('menus.types.data')."')]", 380);

            $this->capture($browser, '08-menu-data-table', [
                ["//label[contains(., '".__('menus.types.data')."')]", 1],
                ["//*[contains(@class, 'fi-fo-field')][.//*[contains(., '".__('menus.form.columns')."')]][last()]", 2],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_09_table_designer(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData(export: true);
        $this->admin();
        $orders = CustomTable::query()->where('name', 'bestellungen')->firstOrFail();

        $this->browse(function (Browser $browser) use ($orders): void {
            $this->viewport($browser, self::WIDTH, 1000);
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$orders->id}/edit")
                ->waitFor('[id="form.is_active"]', 15)->pause(800);
            $this->scrollTo($browser, $this->heading(__('tables.form.details')));

            $this->capture($browser, '09-table-designer', [
                [$this->heading(__('tables.form.details')).'/ancestor::section[1]', 1],
                ['//*[contains(@class, "fi-ta-header-heading")][normalize-space(.)="'.__('tables.fields.title').'"]/ancestor::*[contains(@class, "fi-ta")][1]', 2],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_18_table_rules(string $locale): void
    {
        $this->useLocale($locale);
        $this->demoData(export: true);
        $this->admin();
        $orders = CustomTable::query()->where('name', 'bestellungen')->firstOrFail();

        $this->browse(function (Browser $browser) use ($orders): void {
            $this->viewport($browser, self::WIDTH, 900);
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$orders->id}/edit")
                ->waitFor('[id="form.is_active"]', 15)->pause(800);
            // Regel aufklappen, damit ihre Einstellungen sichtbar sind.
            $this->scrollTo($browser, $this->heading(__('tables.rules.title')), 90);

            $this->capture($browser, '18-table-rules', [
                [$this->heading(__('tables.rules.title')).'/ancestor::section[1]', 1],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_11_identity_provider(string $locale): void
    {
        $this->useLocale($locale);
        $this->admin();
        $provider = $this->markTested($this->entraProvider(['name' => $this->text('Company login', 'Firmen-Login', 'Connexion entreprise', 'Accesso aziendale')]));

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->viewport($browser, self::WIDTH, 1000);
            $this->login($browser, self::ADMIN)->visit("/admin/identity-providers/{$provider->slug}/edit")
                ->waitFor('[id="form.is_enabled"]', 15)->pause(800);

            $this->capture($browser, '11-identity-provider', [
                ["//*[self::a or self::button][contains(., '".__('idp.test.run')."')]", 1],
                ["//*[contains(text(), '".__('idp.entra.redirect_uri').":')]", 2],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_12_database_connection(string $locale): void
    {
        $this->useLocale($locale);
        $this->admin();
        $fixture = ExternalResearchDatabase::on(self::EXTERNAL_DATABASE);

        if (! $fixture->isCurrent()) {
            $fixture->reset();
        }

        $own = config('database.connections.pgsql');
        $connection = DatabaseConnection::create([
            'name' => $this->text('Research (demo)', 'Forschung (Demo)', 'Recherche (démo)', 'Ricerca (demo)'),
            'driver' => ConnectionDriver::Pgsql,
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => self::EXTERNAL_DATABASE,
            'username' => $own['username'],
            'password' => $own['password'],
            'default_schema' => ExternalResearchDatabase::SCHEMA,
        ]);
        app(ConnectionTester::class)->test($connection);

        $this->browse(function (Browser $browser) use ($connection): void {
            $this->viewport($browser, self::WIDTH, 1000);
            $this->login($browser, self::ADMIN)->visit("/admin/database-connections/{$connection->id}/edit")
                ->waitFor('[id="form.default_schema"]', 15)->pause(800);

            $this->capture($browser, '12-database-connection', [
                ["//*[self::a or self::button][contains(., '".__('db_connections.test.run')."')]", 1],
                ["//*[contains(@class, 'fi-fo-field')][.//input[@id='form.default_schema']]", 2],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_13_table_connector_create(string $locale): void
    {
        $this->useLocale($locale);
        $this->admin();
        $connection = $this->researchDemoConnection();

        $this->browse(function (Browser $browser) use ($connection): void {
            $this->viewport($browser, self::WIDTH, 900);
            $this->login($browser, self::ADMIN)->visit('/admin/table-connector/create')->waitFor('[id="form.database_connection_id"]', 15);
            $browser->select('[id="form.database_connection_id"]', (string) $connection->id)->pause(1500);
            $this->pickOption($browser, 'form.external_table', 'projekte');
            $browser->waitFor('[id="form.name"]', 15)->pause(1200);

            $this->capture($browser, '13-table-connector-create', [
                ["//section[contains(@class, 'fi-section')][.//*[@id='form.external_table']]", 1],
                ["//*[contains(@class, 'fi-sc-text')][contains(., 'id')][1]", 2],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_14_table_connector_columns(string $locale): void
    {
        $this->useLocale($locale);
        $this->admin();
        $connection = $this->researchDemoConnection();
        // Zieltabellen zuerst: Die Fremdschlüssel der Projekte werden so gleich zu Verknüpfungen.
        foreach (['personen' => ['Personen', 'People'], 'organisationen' => ['Organisationen', 'Organisations']] as $name => [$de, $en]) {
            app(TableConnectorService::class)->connect($connection, ExternalResearchDatabase::SCHEMA, $name, ['name' => $name, 'label' => ['de' => $de, 'en' => $en]]);
        }

        $table = app(TableConnectorService::class)->connect($connection, ExternalResearchDatabase::SCHEMA, 'projekte', [
            'name' => 'forschung_projekte',
            'label' => ['de' => 'Projekte', 'en' => 'Projects', 'fr' => 'Projets', 'it' => 'Progetti'],
        ]);

        $this->browse(function (Browser $browser) use ($table): void {
            $this->viewport($browser, self::WIDTH, 1000);
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$table->id}/edit")->waitFor('[id="form.is_active"]', 15);
            $this->scrollThroughPage($browser);
            $browser->waitFor('.fi-resource-relation-manager tr.fi-ta-row', 15);
            $this->scrollTo($browser, "//*[contains(@class, 'fi-resource-relation-manager')]//*[contains(@class, 'fi-ta-header-heading')]", 120);

            $this->capture($browser, '14-table-connector-columns', [
                ["//*[contains(@class, 'fi-resource-relation-manager')]//*[contains(@class, 'fi-ta')][1]", 1],
                ["//*[contains(@class, 'fi-resource-relation-manager')]//th[contains(@class, 'fi-ta-header-cell-generated')]", 2],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_15_table_connector_writing(string $locale): void
    {
        $this->useLocale($locale);
        $this->admin();
        $connection = $this->researchDemoConnection();
        $connection->update(['is_read_only' => false]);
        $service = app(TableConnectorService::class);
        $table = $service->connect($connection, ExternalResearchDatabase::SCHEMA, 'organisationen', [
            'name' => 'organisationen',
            'label' => ['de' => 'Organisationen', 'en' => 'Organisations'],
        ]);
        $service->update($table, ['allow_create' => true, 'allow_update' => true]);

        $this->browse(function (Browser $browser) use ($table): void {
            $this->viewport($browser, self::WIDTH, 900);
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$table->id}/edit")->waitFor('[id="form.allow_create"]', 15);
            $this->scrollTo($browser, $this->heading(__('table_connector.form.write')), 200);

            $this->capture($browser, '15-table-connector-writing', [
                [$this->heading(__('table_connector.form.write')).'/ancestor::section[1]', 1],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_16_color_scheme(string $locale): void
    {
        $this->useLocale($locale);
        $this->admin();
        $settings = app(AppSettings::class);
        $settings->color_scheme = ColorScheme::HfhRed->value;
        $settings->save();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, 760);
            $this->login($browser, self::ADMIN)->visit('/admin/app-settings')->waitFor('input[type=radio][value="hfh_red"]', 15);
            $this->scrollTo($browser, $this->heading(__('settings.appearance')), 120);

            $this->capture($browser, '16-color-scheme', [
                [$this->heading(__('settings.appearance')).'/ancestor::section[1]', 1],
            ]);
        });
    }

    #[DataProvider('locales')]
    public function test_17_my_settings(string $locale): void
    {
        $this->useLocale($locale);
        $this->admin()->forceFill(['high_contrast' => true])->save();

        $this->browse(function (Browser $browser): void {
            $this->viewport($browser, self::WIDTH, 620);
            $this->login($browser, self::ADMIN)->visit('/admin/my-settings')->waitFor('input[type=radio][value="on"]', 15);

            $this->capture($browser, '17-my-settings', [
                ["//*[contains(@class, 'fi-user-menu-trigger')]", 1, 'right'],
                [$this->heading(__('my_settings.appearance')).'/ancestor::section[1]', 2],
            ]);
        });
    }

    /** Verbindung „Forschung“ zur externen Testdatenbank (wird für Bilder 12–15 gebraucht). */
    private function researchDemoConnection(): DatabaseConnection
    {
        $fixture = ExternalResearchDatabase::on(self::EXTERNAL_DATABASE);

        if (! $fixture->isCurrent()) {
            $fixture->reset();
        }

        $own = config('database.connections.pgsql');

        return DatabaseConnection::create([
            'name' => $this->text('Research (demo)', 'Forschung (Demo)', 'Recherche (démo)', 'Ricerca (demo)'),
            'driver' => ConnectionDriver::Pgsql,
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => self::EXTERNAL_DATABASE,
            'username' => $own['username'],
            'password' => $own['password'],
            'default_schema' => ExternalResearchDatabase::SCHEMA,
        ]);
    }

    private function useLocale(string $locale): void
    {
        $this->locale = $locale;
        app()->setLocale($locale);
    }

    /** Beispieltext in der Sprache der Aufnahme. */
    private function text(string $en, string $de, string $fr, string $it): string
    {
        return ['de' => $de, 'fr' => $fr, 'it' => $it][$this->locale] ?? $en;
    }

    private function admin(): User
    {
        $user = $this->superAdmin();
        $user->forceFill(['name' => 'Alex Admin', 'locale' => $this->locale])->save();

        return $user;
    }

    private function demoData(bool $export = false): void
    {
        $this->seed(DemoDataSeeder::class);

        if ($export) {
            CustomTable::query()->update(['export_enabled' => true]);
        }
    }

    /** Weniger Spalten, damit die Kundenliste samt „Details“ ins Bild passt. */
    private function compactCustomers(): void
    {
        CustomTable::query()->where('name', 'kunden')->firstOrFail()
            ->fields()->whereIn('name', ['ansprechpartner', 'email', 'plz'])->update(['show_in_table' => false]);
    }

    private function samlProvider(): IdentityProvider
    {
        return IdentityProvider::create([
            'type' => IdentityProviderType::Saml,
            'name' => $this->text('Partner portal', 'Partnerportal', 'Portail partenaires', 'Portale partner'),
            'slug' => 'partner',
            'is_enabled' => true,
            'settings' => [
                'idp_entity_id' => 'https://idp.example.com',
                'idp_sso_url' => 'https://idp.example.com/sso',
                'idp_certificate' => Certificates::generateSelfSigned('idp.example.com')['certificate'],
                'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
                'sign_requests' => false,
                'attribute_email' => 'email',
            ],
        ]);
    }

    /** Stellt die Größe des sichtbaren Bereichs ein, nicht die des Fensters. */
    private function viewport(Browser $browser, int $width, int $height): void
    {
        $browser->resize($width, $height);
        [$innerWidth, $innerHeight] = $browser->script('return [window.innerWidth, window.innerHeight];')[0];
        $browser->resize(2 * $width - (int) $innerWidth, 2 * $height - (int) $innerHeight)->pause(300);
    }

    private function heading(string $text): string
    {
        return "//*[contains(@class, 'fi-section-header-heading')][normalize-space(.)='{$text}']";
    }

    /** Scrollt, bis das Element so weit unter dem oberen Rand steht. */
    private function scrollTo(Browser $browser, string $xpath, int $offset = 90): void
    {
        $browser->driver->executeScript(
            'const el = document.evaluate(arguments[0], document, null, 9, null).singleNodeValue;'
            .'if (!el) throw new Error("Nicht gefunden: " + arguments[0]);'
            .'window.scrollBy(0, el.getBoundingClientRect().top - arguments[1]);',
            [$xpath, $offset],
        );
        $browser->pause(500);
    }

    /**
     * Speichert den sichtbaren Bereich mit nummerierten Markierungen.
     *
     * @param  list<array{0: string, 1: int, 2?: string}>  $markers  XPath, Nummer, Lage der Ziffer (left/right)
     */
    private function capture(Browser $browser, string $name, array $markers = []): void
    {
        $directory = base_path('docs/manual/images/'.$this->locale);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $items = array_map(static fn (array $marker): array => [
            'xpath' => $marker[0],
            'n' => $marker[1],
            'side' => $marker[2] ?? 'left',
        ], $markers);

        $browser->driver->executeScript(<<<'JS'
            const [items] = arguments;
            document.activeElement?.blur();
            const style = document.createElement('style');
            style.textContent = '*{scrollbar-width:none!important} *::-webkit-scrollbar{display:none!important}';
            document.head.append(style);
            for (const item of items) {
                const el = document.evaluate(item.xpath, document, null, 9, null).singleNodeValue;
                if (!el) throw new Error('Markierung ' + item.n + ' nicht gefunden: ' + item.xpath);
                const r = el.getBoundingClientRect();
                const pad = 4;
                const box = document.createElement('div');
                Object.assign(box.style, {
                    position: 'fixed', left: (r.left - pad) + 'px', top: (r.top - pad) + 'px',
                    width: (r.width + 2 * pad) + 'px', height: (r.height + 2 * pad) + 'px',
                    border: '2px solid #dc2626', borderRadius: '8px', zIndex: 2147483646, pointerEvents: 'none',
                });
                const size = 26;
                const x = item.side === 'right' ? r.right + pad - size / 2 : r.left - pad - size / 2;
                const badge = document.createElement('div');
                badge.textContent = item.n;
                Object.assign(badge.style, {
                    position: 'fixed', left: Math.max(2, Math.min(window.innerWidth - size - 2, x)) + 'px',
                    top: Math.max(2, r.top - pad - size / 2) + 'px', width: size + 'px', height: size + 'px',
                    borderRadius: '50%', background: '#dc2626', color: '#fff', font: 'bold 15px/26px sans-serif',
                    textAlign: 'center', zIndex: 2147483647, pointerEvents: 'none', boxShadow: '0 1px 3px rgba(0,0,0,.35)',
                });
                document.body.append(box, badge);
            }
        JS, [$items]);
        $browser->pause(200);

        $browser->driver->takeScreenshot("{$directory}/{$name}.png");
    }
}
