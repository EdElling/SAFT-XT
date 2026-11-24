<?php

declare(strict_types=1);

namespace Tests;

use App\DynamicData\DynamicDataNamespace;
use App\Models\App;
use App\Models\DynamicRecord;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemTasksSeeder;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\ElementClickInterceptedException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

/**
 * Browser-Tests gegen einen eigenen Server (Port 8010) und die Datenbank SAFT2_dusk – auf
 * PostgreSQL (APP_ENV=browser, .env.browser), MySQL (APP_ENV=browser-mysql, .env.browser-mysql)
 * oder MariaDB (APP_ENV=browser-mariadb, .env.browser-mariadb). Die externe Datenbank des
 * Table-Connectors ist immer PostgreSQL.
 *
 * Jeder Test leert die Datenbank. Deshalb bricht assertDedicatedDatabase() vor dem ersten
 * Schreibzugriff ab, sobald eine andere Datenbank angesprochen würde.
 *
 * Für CI (z. B. GitHub Actions): Vorlage .env.browser(.example), .env.browser-mysql(.example) bzw. .env.browser-mariadb(.example)
 * kopieren, Zugangsdaten setzen; Chrome und Chromedriver über DUSK_CHROME_BINARY und
 * DUSK_CHROMEDRIVER_BINARY.
 */
abstract class DuskTestCase extends BaseTestCase
{
    private const string DATABASE = 'SAFT2_dusk';

    protected const string EXTERNAL_DATABASE = 'SAFT2_ext_dusk';

    /** Umgebung (APP_ENV) => Datenbanktreiber von SAFT. */
    private const array ENVIRONMENTS = ['browser' => 'pgsql', 'browser-mysql' => 'mysql', 'browser-mariadb' => 'mariadb'];

    private const string HOST = '127.0.0.1';

    private const int PORT = 8010;

    private const string TOOLS_DIRECTORY = '.cache/saft-dusk';

    protected const string SELECTOR_EMAIL = 'input[id="form.email"]';

    protected const string SELECTOR_PASSWORD = 'input[id="form.password"]';

    protected const string SELECTOR_SUBMIT = 'button[type="submit"]';

    protected const string SELECTOR_SIDEBAR = '.fi-sidebar';

    protected const string TEXT_LOGIN_FAILED = 'Diese Kombination aus Zugangsdaten';

    private static bool $migrated = false;

    /** @var array<int, array<string, mixed>> Von Migrationen angelegte Einstellungen. */
    private static array $settings = [];

    private static ?Process $server = null;

    #[BeforeClass]
    public static function prepare(): void
    {
        $environment = self::environment();

        if (! is_file(dirname(__DIR__).'/.env.'.$environment)) {
            throw new RuntimeException(".env.{$environment} fehlt (Vorlage .env.{$environment}.example, siehe README).");
        }

        static::useChromedriver(self::tool('chromedriver', 'DUSK_CHROMEDRIVER_BINARY'));
        static::startChromeDriver(['--port=9515']);
        self::startServer();

        static::afterClass(static function (): void {
            self::$server?->stop();
            self::$server = null;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertDedicatedDatabase();
        $this->resetDatabase();
    }

    /** Volle Trennung der Tests: keine Cookies oder Tabs aus dem vorherigen Test. */
    protected function tearDown(): void
    {
        static::closeAll();

        // Eigene Chrome-Profile dieses Tests entfernen (sonst legt ChromeDriver je Sitzung ein
        // Profil in /tmp an, das liegen bleibt und /tmp mit der Zeit füllt).
        foreach ($this->profileDirectories as $directory) {
            (new Filesystem)->deleteDirectory($directory);
        }

        $this->profileDirectories = [];

        parent::tearDown();
    }

    /** @var list<string> Chrome-Profile der Browser dieses Tests */
    private array $profileDirectories = [];

    /** Neues, leeres Profilverzeichnis je Browser im Temp-Verzeichnis; tearDown() löscht es wieder. */
    private function profileDirectory(): string
    {
        $directory = sys_get_temp_dir().'/saft-dusk-chrome-'.bin2hex(random_bytes(6));
        (new Filesystem)->ensureDirectoryExists($directory, 0700);
        $this->profileDirectories[] = $directory;

        return $directory;
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)
            ->setBinary(self::tool('chrome', 'DUSK_CHROME_BINARY'))
            ->addArguments([
                '--headless=new',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--disable-search-engine-choice-screen',
                '--disable-smooth-scrolling',
                '--window-size='.$this->windowSize(),
                '--user-data-dir='.$this->profileDirectory(),
                '--lang='.$this->browserLanguage(),
                // Passwort-Hinweise von Chrome fangen sonst Klicks ab, bevor sie die Seite erreichen.
                '--disable-features=PasswordLeakDetection,PasswordCheck,PasswordManagerOnboarding',
                // CI (GitHub Actions, Ubuntu 24.04): Die Chrome-Sandbox braucht Benutzer-Namensräume, die
                // AppArmor dort für heruntergeladenes Chrome sperrt; Chrome beendet sich sonst sofort.
                ...(getenv('CI') === 'true' ? ['--no-sandbox'] : []),
            ])
            ->setExperimentalOption('prefs', [
                'credentials_enable_service' => false,
                'profile.password_manager_enabled' => false,
                'profile.password_manager_leak_detection' => false,
                'intl.accept_languages' => $this->browserLanguage(),
            ]);

        return RemoteWebDriver::create(
            'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
        );
    }

    /** Sprache, die der Browser anfragt (Accept-Language). */
    protected function browserLanguage(): string
    {
        return 'de';
    }

    protected function windowSize(): string
    {
        return '1400,1000';
    }

    protected function superAdmin(string $email = 'admin@example.com'): User
    {
        return $this->activeUser($email)->assignRole(User::ROLE_SUPER_ADMIN);
    }

    protected function standardUser(string $email = 'benutzer@example.com'): User
    {
        return $this->activeUser($email)->assignRole(User::ROLE_DEFAULT);
    }

    protected function activeUser(string $email): User
    {
        return User::factory()->create(['email' => $email, 'is_active' => true]);
    }

    /** Füllt das Anmeldeformular aus und sendet es ab; wartet nicht auf das Ergebnis. */
    protected function submitLogin(Browser $browser, string $email, string $password = 'password'): Browser
    {
        return $browser
            ->waitFor(self::SELECTOR_EMAIL)
            ->type(self::SELECTOR_EMAIL, $email)
            ->type(self::SELECTOR_PASSWORD, $password)
            ->click(self::SELECTOR_SUBMIT);
    }

    /**
     * Table-Designer (Bearbeiten): zur Feldliste scrollen, die erst sichtbar lädt. Die ersten Reiter der Seite sind die
     * Sprachen von Anzeigename und Beschreibung, die Feldliste steht unter den letzten Reitern.
     */
    protected function openFieldList(Browser $browser): Browser
    {
        $browser->waitForText('Verwendet in', 15);
        $browser->script("[...document.querySelectorAll('.fi-tabs')].pop().scrollIntoView()");

        return $browser->waitForText('Feld hinzufügen', 15);
    }

    protected function login(Browser $browser, string $email, string $password = 'password'): Browser
    {
        $browser->visit('/admin/login');

        return $this->submitLogin($browser, $email, $password)->waitFor(self::SELECTOR_SIDEBAR, 15);
    }

    /** Untermenü ohne eigene Seite per Klick aufklappen und einen Eintrag darin öffnen. */
    protected function openSubmenuEntry(Browser $browser, string $submenu, string $entry): Browser
    {
        $xpath = "//li[contains(@class, 'fi-sidebar-item')][.//span[contains(@class, 'saft-submenu-label')][normalize-space(.)='{$submenu}']]"
            ."//ul[contains(@class, 'fi-sidebar-sub-group-items')]//a[normalize-space(.)='{$entry}']";
        $visible = static fn (): bool => ($el = $browser->driver->findElements(WebDriverBy::xpath($xpath))) !== [] && $el[0]->isDisplayed();

        if (! $visible()) {
            $browser->clickLink($submenu);
            $browser->waitUsing(15, 100, $visible);
        }

        return $browser->clickAtXPath($xpath);
    }

    protected function logout(Browser $browser): Browser
    {
        return $browser
            ->click('.fi-user-menu-trigger')
            ->waitForText('Abmelden')
            ->press('Abmelden')
            ->waitFor(self::SELECTOR_EMAIL, 15);
    }

    protected function clickRow(Browser $browser, string $text): Browser
    {
        return $browser->waitForText($text, 15)
            ->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., '{$text}')]//td[contains(@class, 'fi-ta-cell')][1]");
    }

    protected function rowAction(Browser $browser, string $rowText, string $action): Browser
    {
        // Zeilenaktionen sind meist Symbole (Bezeichnung als aria-label, B-113); sichtbare nehmen (Desktop/Smartphone).
        return $browser->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., '{$rowText}')]//*[self::button or self::a]"
            ."[contains(., '{$action}') or @aria-label='{$action}'][not(ancestor-or-self::*[contains(@class, 'saft-mobile-only')])]");
    }

    /**
     * „Details“ öffnet den Slider – den gibt es nur in Smartphone-Breite (am Desktop direkt Bearbeiten/Löschen,
     * DynamicRecordActions::rowActions). Deshalb vorher schmal stellen.
     */
    protected function mobile(Browser $browser): Browser
    {
        $browser->resize(400, 1000);

        return $browser->pause(300);
    }

    protected function openDetails(Browser $browser, string $rowText, string $button = 'Details'): Browser
    {
        if ($button === 'Details') {
            $this->mobile($browser);
        }

        return $browser->waitForText($rowText, 15)
            ->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., '{$rowText}')]//button[contains(., '{$button}')]")
            // Sprachunabhängig auf das Popup warten, dann auf das Ende der Einblendung.
            ->waitFor('.fi-modal-window .fi-modal-footer', 15)
            ->pause(400);
    }

    /** @return list<string> Texte der hervorgehobenen Zeilen, von oben nach unten */
    protected function selectedRows(Browser $browser): array
    {
        return array_map(
            static fn (RemoteWebElement $row): string => trim($row->getText()),
            $browser->elements('tr.saft-row-selected'),
        );
    }

    /** Meldung der Browser-Prüfung (required, max …): Sie verhindert das Absenden vor dem Server. */
    protected function nativeValidationMessage(Browser $browser, string $selector): string
    {
        $selector = json_encode($selector);

        return (string) $browser->script("return document.querySelector({$selector}).validationMessage;")[0];
    }

    /** Damit auch die Prüfung des Servers erreicht wird. */
    protected function disableNativeValidation(Browser $browser): Browser
    {
        $browser->script("document.querySelectorAll('form').forEach(form => form.noValidate = true);");

        return $browser;
    }

    /**
     * Öffnet die Filter der n-ten Tabelle der Seite, füllt sie aus und wendet sie an.
     *
     * @param  callable(Browser): void  $fill
     */
    protected function applyFilters(Browser $browser, callable $fill, int $table = 1): Browser
    {
        $this->openFilters($browser, $table);
        $fill($browser);

        return $browser->clickAtXPath("(//button[contains(., 'Filter anwenden')])[{$table}]")->pause(600);
    }

    /**
     * Öffnet das Filter-Panel und wartet, bis es wirklich offen ist. Das Panel bleibt nach
     * „Filter zurücksetzen“ offen; ein Klick während des Auf- oder Zuklappens (langsame Rechner,
     * CI) würde es sonst gleich wieder schließen – daher höchstens drei Versuche.
     */
    protected function openFilters(Browser $browser, int $table = 1): Browser
    {
        $apply = "(//button[contains(., 'Filter anwenden')])[{$table}]";
        $visible = static fn (): bool => $browser->driver->findElement(WebDriverBy::xpath($apply))->isDisplayed();

        for ($attempt = 0; $attempt < 3 && ! $visible(); $attempt++) {
            $browser->clickAtXPath("(//button[@aria-label='Filtern'])[{$table}]");

            try {
                $browser->waitUsing(5, 100, $visible);
            } catch (TimeoutException) {
                // Nächster Versuch.
            }
        }

        return $browser->pause(300);
    }

    /**
     * Setzt die Filter einer Tabelle zurück – bevorzugt über das „×“ bei „Aktive Filter“: Das braucht kein
     * Filter-Panel, dessen Öffnen auf langsamen Rechnern (CI) mitten in der Animation scheitern kann.
     */
    protected function resetFilters(Browser $browser, int $table = 1): Browser
    {
        $removeAll = <<<'JS'
            const box = document.querySelectorAll('.fi-ta-filter-indicators')[arguments[0] - 1];
            const button = box && [...box.querySelectorAll('button')].find((b) => ! b.closest('.fi-ta-filter-indicators-badges-ctn'));
            if (! button) return false;
            button.click();
            return true;
        JS;

        if ($browser->driver->executeScript($removeAll, [$table])) {
            return $browser->waitUsing(15, 100, static fn (): bool => ! $browser->driver->executeScript(
                'return !! document.querySelectorAll(".fi-ta-filter-indicators")[arguments[0] - 1];',
                [$table],
            ))->pause(500);
        }

        $this->openFilters($browser, $table);

        return $browser->clickAtXPath("(//button[contains(., 'Filter zurücksetzen')])[{$table}]")->pause(800);
    }

    /** Anders als clear() löst das Eingabeereignisse aus, die Livewire mitbekommt. */
    protected function emptyField(Browser $browser, string $selector): Browser
    {
        return $browser->keys($selector, ['{control}', 'a'], '{backspace}');
    }

    /**
     * Ziehen in Schritten: SortableJS (forceFallback) beginnt erst nach einigen Mausbewegungen zu ziehen;
     * Dusks drag() springt in einem Schritt zum Ziel.
     */
    protected function dragInSteps(Browser $browser, string $from, string $to): void
    {
        $source = $browser->driver->findElement(WebDriverBy::cssSelector($from));
        $target = $browser->driver->findElement(WebDriverBy::cssSelector($to));
        $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$source]);
        $actions = $browser->driver->action()->moveToElement($source)->clickAndHold();

        foreach (range(1, 5) as $step) {
            $actions = $actions->moveByOffset(0, 8);
        }

        $actions->moveToElement($target, 0, -10)->moveByOffset(0, 4)->release()->perform();
        $browser->pause(600);
    }

    /** Wählt einen Eintrag in einem JavaScript-Auswahlfeld, bezogen auf die ID seines Buttons. */
    protected function pickOption(Browser $browser, string $buttonId, string $text): Browser
    {
        $option = "//button[@id='{$buttonId}']/ancestor::*[contains(@class, 'fi-select-input-ctn')][1]//*[contains(@class, 'fi-select-input-option')][contains(., ".self::xpathLiteral($text).')]';

        // Benachrichtigungen oben rechts können das Feld verdecken.
        $browser->driver->executeScript('document.querySelectorAll(".fi-no-notification").forEach((n) => n.remove());');
        $browser->click("button[id=\"{$buttonId}\"]")->pause(400);
        $browser->driver->executeScript(
            'document.evaluate(arguments[0], document, null, 9, null).singleNodeValue?.scrollIntoView({block: "center"});',
            [$option],
        );

        return $browser->clickAtXPath($option)->pause(400);
    }

    /** Text als XPath-Literal – auch mit Apostroph (Faker-Namen wie „O'Conner“ brachen den Ausdruck sonst). */
    protected static function xpathLiteral(string $text): string
    {
        if (! str_contains($text, "'")) {
            return "'{$text}'";
        }

        return "concat('".implode("', \"'\", '", explode("'", $text))."')";
    }

    /**
     * Angebotene Einträge eines JavaScript-Auswahlfelds (öffnet und schließt die Liste).
     *
     * @return list<string>
     */
    protected function jsSelectOptions(Browser $browser, string $buttonId): array
    {
        $browser->click("button[id=\"{$buttonId}\"]")->pause(500);
        $texts = $browser->driver->executeScript(<<<'JS'
            const container = document.getElementById(arguments[0])?.closest('.fi-select-input-ctn');
            return [...(container?.querySelectorAll('.fi-select-input-option') ?? [])]
                .filter(option => option.offsetParent !== null)
                .map(option => option.innerText.trim());
        JS, [$buttonId]);
        // Nicht mit Escape schließen: Das schlösse auch ein umgebendes Popup.
        $browser->click("button[id=\"{$buttonId}\"]")->pause(300);

        return array_values((array) $texts);
    }

    /** Klickt mittig im Fenster, damit die feste Kopfleiste das Ziel nicht überdeckt. */
    protected function clickCentered(Browser $browser, string $xpath): Browser
    {
        $element = $browser->driver->findElement(WebDriverBy::xpath($xpath));
        $browser->driver->executeScript('arguments[0].scrollIntoView({block: "center"});', [$element]);
        $browser->pause(200);
        $element->click();

        return $browser;
    }

    /** Sichtbarer Text der ganzen Seite samt Fenstertitel. */
    protected function visibleText(Browser $browser): string
    {
        return (string) $browser->script('return document.title + " " + document.body.innerText;')[0];
    }

    /** Scrollt die Seite durch, damit nachladende Bereiche (Widgets) gerendert werden. */
    protected function scrollThroughPage(Browser $browser): Browser
    {
        foreach ([0.33, 0.66, 1] as $share) {
            $browser->script("window.scrollTo(0, document.body.scrollHeight * {$share});");
            $browser->pause(500);
        }

        return $browser;
    }

    /** @return list<string> Text der ersten Spalte aller sichtbaren Zeilen */
    protected function firstColumn(Browser $browser): array
    {
        // In einem Schritt lesen: Livewire kann die Tabelle sonst zwischen zwei Abfragen ersetzen.
        return $browser->script(<<<'JS'
            return [...document.querySelectorAll('tr.fi-ta-row')]
                .map(row => row.querySelector('td.fi-ta-cell').innerText.trim());
        JS)[0];
    }

    /** Für Selektoren mit verborgenen Varianten, z. B. die mobile Blättern-Schaltfläche. */
    protected function clickVisible(Browser $browser, string $selector): Browser
    {
        foreach ($browser->elements($selector) as $element) {
            if ($element->isDisplayed()) {
                $element->click();

                return $browser;
            }
        }

        throw new RuntimeException("Kein sichtbares Element für {$selector} gefunden.");
    }

    /**
     * Klickt den obersten sichtbaren Button dieser Beschriftung: Bei gestapelten Popups
     * fangen die oberen Fenster den Klick auf verdeckte Buttons ab.
     */
    /** Zeilen je Seite der (ersten) Liste umstellen; Vorgabe der Datenseiten sind 10. */
    protected function showRowsPerPage(Browser $browser, int $rows = 25): Browser
    {
        $select = '.fi-pagination-records-per-page-select:not(.fi-compact) select';

        $browser->select($select, (string) $rows)
            ->waitUsing(15, 100, fn (): bool => $browser->value($select) === (string) $rows)
            ->pause(500);

        // Die Auswahl steht unten; danach wieder nach oben, damit Kopfaktionen klickbar sind.
        $browser->script('window.scrollTo(0, 0);');

        return $browser;
    }

    protected function clickVisibleButton(Browser $browser, string $label): Browser
    {
        // Auch Symbol-Knöpfe (Zeilenaktionen, B-113): Bezeichnung im aria-label.
        $buttons = $browser->driver->findElements(WebDriverBy::xpath("//button[normalize-space(.)='{$label}' or @aria-label='{$label}']"));

        foreach (array_reverse($buttons) as $button) {
            if (! $button->isDisplayed()) {
                continue;
            }

            try {
                $button->click();

                return $browser;
            } catch (ElementClickInterceptedException) {
                continue;
            }
        }

        throw new RuntimeException("Kein klickbarer Button „{$label}“ gefunden.");
    }

    /** Umgebung aus APP_ENV (phpunit.dusk.xml bzw. phpunit.dusk.mysql.xml). */
    private static function environment(): string
    {
        $environment = (string) (getenv('APP_ENV') ?: 'browser');

        if (! array_key_exists($environment, self::ENVIRONMENTS)) {
            throw new RuntimeException("Browser-Tests nur mit APP_ENV=browser, browser-mysql oder browser-mariadb, nicht {$environment}.");
        }

        return $environment;
    }

    /** Tabellen des Table-Designers im Namensraum (PostgreSQL: app_data.x, MySQL: SAFT2_dusk_app_data.x). */
    protected function qualified(string $table): string
    {
        return app(DynamicDataNamespace::class)->qualify($table);
    }

    private function assertDedicatedDatabase(): void
    {
        $driver = self::ENVIRONMENTS[self::environment()];
        $actual = [
            'Umgebung' => app()->environment(),
            'Treiber' => DB::connection()->getDriverName(),
            'Standardverbindung' => DB::connection()->getDatabaseName(),
            // PostgreSQL: Schema in derselben Datenbank; MySQL: eigene Datenbank <DB>_app_data.
            'app_data-Verbindung' => $driver === 'pgsql' ? DB::connection('app_data')->getDatabaseName() : config('dynamic_data.namespace'),
            // Wird von Tests des Table-Connectors geleert (ExternalResearchDatabase).
            'externe Testdatenbank' => env('EXT_DB_DATABASE', self::EXTERNAL_DATABASE),
        ];
        $expected = [
            'Umgebung' => self::environment(),
            'Treiber' => $driver,
            'Standardverbindung' => self::DATABASE,
            'app_data-Verbindung' => $driver === 'pgsql' ? self::DATABASE : self::DATABASE.'_app_data',
            'externe Testdatenbank' => self::EXTERNAL_DATABASE,
        ];

        if ($actual !== $expected) {
            throw new RuntimeException('Browser-Test abgebrochen, falsches Ziel: '.json_encode($actual));
        }
    }

    private function resetDatabase(): void
    {
        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$settings = array_map(static fn (object $row): array => (array) $row, DB::table('settings')->get()->all());
            self::$migrated = true;
        }

        app(DynamicDataNamespace::class)->reset();
        DynamicRecord::forget();

        // Systemtabellen leeren; MySQL lehnt TRUNCATE bei Fremdschlüsseln sonst ab.
        $schema = DB::connection()->getDriverName() === 'pgsql' ? 'public' : DB::connection()->getDatabaseName();

        Schema::withoutForeignKeyConstraints(static function () use ($schema): void {
            foreach (Schema::getTables($schema) as $table) {
                if ($table['name'] !== 'migrations') {
                    DB::table($table['name'])->truncate();
                }
            }
        });

        DB::table('settings')->insert(self::$settings);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RolesAndPermissionsSeeder::class, MenuSeeder::class, SystemTasksSeeder::class]);
        App::query()->create(['name' => ['de' => 'Test-App', 'en' => 'Test app'], 'sort_order' => 1]);
    }

    private static function startServer(): void
    {
        $root = dirname(__DIR__);

        self::$server = new Process(
            ['php', '-S', self::HOST.':'.self::PORT, '-t', '.', '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
            $root.'/public',
            ['APP_ENV' => self::environment(), 'PHP_CLI_SERVER_WORKERS' => '4'],
            null,
            null,
        );
        // Das Zugriffsprotokoll würde sonst den Pipe-Puffer füllen und den Server blockieren.
        self::$server->disableOutput();
        self::$server->start();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen(self::HOST, self::PORT, timeout: 0.2);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException('Test-Server nicht erreichbar: '.self::HOST.':'.self::PORT);
    }

    private static function tool(string $name, string $override): string
    {
        $configured = getenv($override);

        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }

        $matches = glob(getenv('HOME').'/'.self::TOOLS_DIRECTORY.'/'.$name.'/*/'.$name.'-linux64/'.$name) ?: [];

        return end($matches) ?: throw new RuntimeException("{$name} nicht gefunden, siehe README ({$override}).");
    }
}
