<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Auth\AuthMode;
use App\DynamicData\DataMenu;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Dusk\Browser;
use RuntimeException;
use Tests\Concerns\ConfiguresAuth;
use Tests\DuskTestCase;

/** Fehlerdarstellung, Oberfläche und Betrieb (Bereiche ERR, OBF, BTR). */
class SystemTest extends DuskTestCase
{
    use ConfiguresAuth;

    private const string ADMIN = 'admin@example.com';

    private function customers(): CustomTable
    {
        $table = app(DynamicSchemaService::class)->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string'],
        ]);
        app(DataMenu::class)->ensure($table);
        DynamicRecord::forTable($table)->create(['firma' => 'ACME GmbH', 'ort' => 'Berlin']);
        DynamicRecord::forTable($table)->create(['firma' => 'Beta AG', 'ort' => 'Hamburg']);

        return $table;
    }

    private function assertNoHorizontalOverflow(Browser $browser, string $where): void
    {
        [$overflow] = $browser->script('return document.documentElement.scrollWidth - window.innerWidth;');
        $this->assertLessThanOrEqual(1, $overflow, $where);
    }

    /** Kontrast zwischen Text und Hintergrund eines Elements nach WCAG. */
    private function contrast(Browser $browser, string $selector): float
    {
        $selector = json_encode($selector);

        return (float) $browser->script(<<<JS
            const canvas = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
            const rgba = (color) => {
                canvas.clearRect(0, 0, 1, 1);
                canvas.fillStyle = color;
                canvas.fillRect(0, 0, 1, 1);
                return [...canvas.getImageData(0, 0, 1, 1).data];
            };
            const light = ([r, g, b]) => {
                const part = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
                return 0.2126 * part(r) + 0.7152 * part(g) + 0.0722 * part(b);
            };
            const element = document.querySelector({$selector});
            // Halbtransparente Hintergründe (z. B. die Hervorhebung) mit den darunterliegenden verrechnen.
            const layers = [];
            for (let node = element; node; node = node.parentElement) {
                const color = rgba(getComputedStyle(node).backgroundColor);
                if (color[3] > 0) {
                    layers.push(color);
                }
                if (color[3] === 255) {
                    break;
                }
            }
            let result = (layers.pop() || [255, 255, 255, 255]).slice(0, 3);
            while (layers.length) {
                const [r, g, b, alpha] = layers.pop();
                result = [r, g, b].map((value, index) => value * (alpha / 255) + result[index] * (1 - alpha / 255));
            }
            const a = light(rgba(getComputedStyle(element).color));
            const b = light(result);
            return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
        JS)[0];
    }

    /** ERR-02 */
    public function test_server_error_shows_a_reference_and_no_technical_details(): void
    {
        $this->superAdmin();
        $this->customers();
        $log = storage_path('logs/laravel.log');
        $offset = is_file($log) ? filesize($log) : 0;

        $this->browse(function (Browser $browser) use ($log, $offset): void {
            $this->login($browser, self::ADMIN);

            // PostgreSQL: neuer Name ohne Schema; MySQL/MariaDB: mit Datenbank, sonst fehlt das Ziel.
            Schema::connection('app_data')->rename($this->qualified('kunden'), in_array(DB::connection('app_data')->getDriverName(), ['mysql', 'mariadb'], true) ? $this->qualified('kunden_umbenannt') : 'kunden_umbenannt');

            $browser->visit('/admin/data/kunden')->waitForText('Unerwarteter Fehler', 15);
            $page = $this->visibleText($browser);

            $this->assertDoesNotMatchRegularExpression('/SQLSTATE|select |app_data|vendor\//i', $page);
            $this->assertSame(1, preg_match('/Referenz: ([A-Z0-9]{8})/', $page, $reference));
            $this->assertStringContainsString($reference[1], (string) file_get_contents($log, offset: $offset));

            $browser->assertSeeLink('Zur Startseite');
        });
    }

    /** ERR-04 */
    public function test_message_stays_until_closed_and_page_remains_usable(): void
    {
        $this->superAdmin();
        $table = $this->customers();
        $orders = app(DynamicSchemaService::class)->createTable(['name' => 'auftraege', 'label' => 'Aufträge'], [
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        DynamicRecord::forTable($orders)->create(['kunde_id' => DynamicRecord::forTable($table)->newQuery()->where('firma', 'ACME GmbH')->value('id')]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden');
            $this->openDetails($browser, 'ACME GmbH');
            $this->clickVisibleButton($browser, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')->waitFor('.fi-no-notification', 15)->pause(7000)
                ->assertPresent('.fi-no-notification')
                ->click('.fi-no-notification button')
                ->waitUntilMissing('.fi-no-notification', 15);

            // Die Rückfrage bleibt nach der Löschsperre offen und lässt sich normal schließen.
            $this->clickVisibleButton($browser, 'Abbrechen')->pause(600);
            $this->clickVisibleButton($browser, 'Schliessen')->pause(600);
            $this->clickRow($browser, 'Beta AG')->waitFor('tr.saft-row-selected', 15)->assertSeeIn('tr.saft-row-selected', 'Beta AG');
        });
    }

    /** ERR-05 */
    public function test_error_page_follows_the_dark_system_setting(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
                'cmd' => 'Emulation.setEmulatedMedia',
                'params' => ['features' => [['name' => 'prefers-color-scheme', 'value' => 'dark']]],
            ]);

            $browser->visit('/gibtsnicht')->assertSee('Nicht gefunden');

            [$dark] = $browser->script("return matchMedia('(prefers-color-scheme: dark)').matches;");
            $this->assertTrue($dark);
            $this->assertGreaterThan(4.5, $this->contrast($browser, 'main h1'));

            [$channel] = $browser->script("const c = document.createElement('canvas').getContext('2d'); c.fillStyle = getComputedStyle(document.body).backgroundColor; c.fillRect(0,0,1,1); return c.getImageData(0,0,1,1).data[1];");
            $this->assertLessThan(80, $channel, 'Der Hintergrund muss dunkel sein.');
        });
    }

    /** OBF-01: Prüft Kontraste der Hauptelemente; ersetzt keine vollständige Sichtprüfung. */
    public function test_dark_mode_keeps_main_elements_readable(): void
    {
        $this->superAdmin();
        $this->customers();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            // Das Design gilt pro Benutzer (users.theme); jede Seite übernimmt es in den localStorage.
            User::query()->where('email', self::ADMIN)->update(['theme' => 'dark']);
            $browser->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);

            [$isDark] = $browser->script("return document.documentElement.classList.contains('dark');");
            $this->assertTrue($isDark);
            // Beim Laden wird das Design nicht erneut gespeichert (nur bei einer echten Änderung).
            $browser->pause(500);
            [$posts] = $browser->script("return performance.getEntriesByType('resource').filter((e) => e.name.includes('/my-theme')).length;");
            $this->assertSame(0, $posts);
            $this->assertGreaterThan(4.5, $this->contrast($browser, 'tr.fi-ta-row td'));

            $this->clickRow($browser, 'ACME GmbH')->waitFor('tr.saft-row-selected', 15);
            $this->assertGreaterThan(4.5, $this->contrast($browser, 'tr.saft-row-selected td'));

            [$differs] = $browser->script(<<<'JS'
                const color = (row) => getComputedStyle(document.querySelector(row + ' td')).backgroundColor;
                return color('tr.saft-row-selected') !== color('tr.fi-ta-row:not(.saft-row-selected)');
            JS);
            $this->assertTrue($differs, 'Die ausgewählte Zeile muss sich abheben.');

            $this->openDetails($browser, 'ACME GmbH');
            $this->assertGreaterThan(4.5, $this->contrast($browser, '.fi-modal-window .fi-modal-heading'));
        });
    }

    /** OBF-02 */
    public function test_narrow_window_keeps_menu_list_cascade_and_popup_usable(): void
    {
        $this->superAdmin();
        $this->customers();

        $this->browse(function (Browser $browser): void {
            $browser->resize(375, 800);
            $this->login($browser, self::ADMIN);
            $this->assertNoHorizontalOverflow($browser, 'Dashboard');

            $browser->click('.fi-topbar-open-sidebar-btn')->pause(500)
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Kunden']")
                ->waitForText('ACME GmbH', 15);
            $this->assertNoHorizontalOverflow($browser, 'Liste');

            $this->clickRow($browser, 'ACME GmbH')->waitFor('tr.saft-row-selected', 15)->waitForText('Kunden: ACME GmbH', 15);
            $this->assertNoHorizontalOverflow($browser, 'Kaskade');

            $this->openDetails($browser, 'ACME GmbH')->assertSee('Bearbeiten');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".firma"]', 15);
            $browser->clear('input[id$=".firma"]')->type('input[id$=".firma"]', 'ACME Mobil');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);
        });

        $this->assertSame(1, DynamicRecord::forTableName('kunden')->newQuery()->where('firma', 'ACME Mobil')->count());
    }

    /** OBF-05 */
    public function test_long_names_do_not_break_the_layout(): void
    {
        $this->superAdmin();
        $long = str_repeat('Sehr lange Bezeichnung ', 8).'Ende';
        $table = app(DynamicSchemaService::class)->createTable(['name' => 'lang', 'label' => $long], [
            ['name' => 'titel', 'label' => $long, 'type' => 'string'],
        ]);
        app(DataMenu::class)->ensure($table);
        DynamicRecord::forTable($table)->create(['titel' => $long]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->assertNoHorizontalOverflow($browser, 'Dashboard');

            $browser->visit('/admin/data/lang')->waitFor('tr.fi-ta-row', 15);
            $this->assertNoHorizontalOverflow($browser, 'Liste');

            [$sidebarWidth] = $browser->script("return document.querySelector('.fi-sidebar').getBoundingClientRect().width;");
            // Die Seitenleiste wächst mit dem längsten Eintrag, höchstens bis 28rem (448 px).
            $this->assertLessThanOrEqual(448.5, $sidebarWidth);
        });
    }

    /** OBF-06: Prüft 5000 Datensätze auf dem Testserver, kein Lasttest unter Produktionsbedingungen. */
    public function test_larger_table_stays_responsive(): void
    {
        $this->superAdmin();
        $table = $this->customers();
        $rows = [];

        for ($number = 1; $number <= 5000; $number++) {
            $rows[] = ['firma' => sprintf('Massenkunde %04d', $number), 'ort' => 'Stadt '.($number % 50), 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('app_data')->table($this->qualified('kunden'))->insert($chunk);
        }

        $timed = function (callable $step): float {
            $start = microtime(true);
            $step();

            return microtime(true) - $start;
        };

        $this->browse(function (Browser $browser) use ($timed): void {
            $this->login($browser, self::ADMIN);

            $this->assertLessThan(5, $timed(fn () => $browser->visit('/admin/data/kunden')->waitForText('Massenkunde 5000', 15)), 'Liste');
            $this->assertLessThan(5, $timed(fn () => $browser->click('th.fi-ta-header-cell-firma button')->waitForText('ACME GmbH', 15)), 'Sortieren');
            $this->assertLessThan(5, $timed(fn () => $browser->type('.fi-ta-search-field input', 'Massenkunde 4711')->waitUntilMissingText('ACME GmbH', 15)->waitForText('Massenkunde 4711', 15)), 'Suche');
            $this->assertLessThan(5, $timed(fn () => $this->clickRow($browser, 'Massenkunde 4711')->waitFor('tr.saft-row-selected', 15)), 'Auswahl');
        });
    }

    /** BTR-02 */
    public function test_console_command_switches_the_login_mode(): void
    {
        $this->superAdmin();
        $this->markTested($this->entraProvider());
        $this->setAuthMode(AuthMode::Sso);

        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/login')->waitForText('Anmelden mit Firmen-Login', 15)->assertMissing(self::SELECTOR_EMAIL);

            $this->assertSame(0, Artisan::call('auth:mode', ['mode' => 'local']));

            $browser->visit('/admin/login')->waitFor(self::SELECTOR_EMAIL, 15)->assertDontSee('Anmelden mit Firmen-Login');
            $this->submitLogin($browser, self::ADMIN)->waitFor(self::SELECTOR_SIDEBAR, 15);
        });
    }

    /** BTR-03 */
    public function test_demo_seeder_can_run_again_without_duplicates_or_overwriting(): void
    {
        $this->superAdmin();
        $this->seed(DemoDataSeeder::class);

        $counts = fn (): array => CustomTable::query()->pluck('name')
            ->mapWithKeys(static fn (string $name): array => [$name => DynamicRecord::forTableName($name)->newQuery()->count()])
            ->all();
        $before = $counts();
        ksort($before);
        $this->assertGreaterThan(0, $before['kunden']);

        $kunde = DynamicRecord::forTableName('kunden')->newQuery()->firstOrFail();
        $kunde->update(['firma' => 'Eigene Änderung']);
        $details = CustomTable::query()->where('name', 'kunden')->firstOrFail();
        app(DynamicSchemaService::class)->updateTable($details, ['detail_field_ids' => []]);

        $this->seed(DemoDataSeeder::class);

        $after = $counts();
        ksort($after);
        $this->assertSame($before, $after);
        $this->assertSame('Eigene Änderung', DynamicRecord::forTableName('kunden')->newQuery()->findOrFail($kunde->id)->getAttribute('firma'));

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Eigene Änderung', 15)->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** BTR-04 */
    public function test_verify_command_is_clean_after_designer_work_in_the_browser(): void
    {
        $this->superAdmin();
        $table = $this->customers();

        $this->browse(function (Browser $browser) use ($table): void {
            $this->openFieldList($this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$table->id}/edit"));

            $this->clickVisibleButton($browser, 'Feld hinzufügen')->waitFor('[id="mountedActionSchema0.label.de"]', 15);
            $browser->type('[id="mountedActionSchema0.label.de"]', 'Telefon')->type('[id="mountedActionSchema0.name"]', 'telefon');
            $this->clickVisibleButton($browser, 'Erstellen')->waitUntilMissing('[id="mountedActionSchema0.label.de"]', 15);

            $this->rowAction($browser, 'ort', 'Bearbeiten')->waitFor('[id="mountedActionSchema0.name"]', 15);
            $browser->clear('[id="mountedActionSchema0.name"]')->type('[id="mountedActionSchema0.name"]', 'stadt');
            $this->clickVisibleButton($browser, 'Speichern')->waitUntilMissing('[id="mountedActionSchema0.name"]', 15);
        });

        $this->assertTrue(CustomField::query()->where('custom_table_id', $table->id)->where('name', 'stadt')->exists());
        $this->assertSame(0, Artisan::call('app-data:verify'));
        $this->assertStringContainsString('konsistent', Artisan::output());
    }

    /** BTR-05 */
    public function test_two_admins_changing_one_table_stay_consistent(): void
    {
        $this->superAdmin();
        $table = $this->customers();

        $this->browse(function (Browser $first, Browser $second) use ($table): void {
            foreach ([[$first, 'Feld Eins', 'feld_eins'], [$second, 'Feld Zwei', 'feld_zwei']] as [$browser, $label, $name]) {
                $this->openFieldList($this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$table->id}/edit"));
                $this->clickVisibleButton($browser, 'Feld hinzufügen')->waitFor('[id="mountedActionSchema0.label.de"]', 15);
                $browser->type('[id="mountedActionSchema0.label.de"]', $label)->type('[id="mountedActionSchema0.name"]', $name)->pause(800);
            }

            $this->clickVisibleButton($first, 'Erstellen');
            $this->clickVisibleButton($second, 'Erstellen');

            foreach ([$first, $second] as $browser) {
                $browser->waitUntilMissing('[id="mountedActionSchema0.label.de"]', 20)->assertDontSee('Unerwarteter Fehler');
            }
        });

        $this->assertSame(2, CustomField::query()->where('custom_table_id', $table->id)->whereIn('name', ['feld_eins', 'feld_zwei'])->count());
        $this->assertSame(0, Artisan::call('app-data:verify'));
    }

    /** BTR-06: Läuft nur auf der Testdatenbank, siehe Prüfung direkt vor dem Befehl. */
    public function test_fresh_migration_leaves_a_clean_working_system(): void
    {
        $this->customers();

        if (DB::connection()->getDatabaseName() !== 'SAFT2_dusk') {
            throw new RuntimeException('migrate:fresh nur auf SAFT2_dusk.');
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]));

        $this->assertSame([], Schema::connection('app_data')->getTables(config('dynamic_data.namespace')));
        $this->assertSame(0, CustomTable::query()->count());
        $this->assertSame(0, Artisan::call('app-data:verify'));

        $this->activeUser('pruefer@example.com')->assignRole(User::ROLE_SUPER_ADMIN);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'pruefer@example.com')->assertPathIs('/admin')->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** OBF-07 */
    public function test_admin_tables_are_only_as_wide_as_their_columns(): void
    {
        $this->superAdmin();
        $table = app(DynamicSchemaService::class)->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);

        $this->browse(function (Browser $browser) use ($table): void {
            $this->login($browser, self::ADMIN);

            foreach (['/admin/roles?tab=all' => '.fi-ta', "/admin/table-designer/{$table->id}/edit" => '.fi-resource-relation-manager .fi-ta'] as $path => $selector) {
                // Tabellen in Bearbeitungsmasken laden erst, wenn sie ins Bild scrollen.
                $this->scrollThroughPage($browser->visit($path)->waitFor('.fi-page-content', 15));
                $browser->waitFor($selector.' .fi-ta-table', 15)->pause(300);
                $layout = $this->tableLayout($browser, $selector);

                // So breit wie die Spalten (bzw. die Mindestbreite für die Blätterleiste), nicht wie die Seite.
                $this->assertLessThanOrEqual(max($layout['columns'], $layout['minimum']) + 2, $layout['table'], $path);
                $this->assertLessThan($layout['content'], $layout['table'], $path);
                $this->assertFalse($layout['pageScrolls'], $path);
                $browser->assertVisible($selector.' .fi-pagination-overview');
            }

            // Das Änderungsprotokoll mit vielen Spalten bleibt im Inhaltsbereich.
            $browser->visit('/admin/activity-log')->waitFor('.fi-ta .fi-ta-table', 15)->pause(300);
            $layout = $this->tableLayout($browser, '.fi-ta');
            $this->assertLessThanOrEqual($layout['content'] + 0.5, $layout['table']);
            $this->assertFalse($layout['pageScrolls']);
        });
    }

    /** @return array{table: float, columns: float, minimum: float, content: float, pageScrolls: bool} */
    private function tableLayout(Browser $browser, string $selector): array
    {
        return $browser->driver->executeScript(<<<'JS'
            const table = document.querySelector(arguments[0]);
            return {
                table: table.getBoundingClientRect().width,
                columns: table.querySelector('.fi-ta-table')?.getBoundingClientRect().width ?? 0,
                minimum: parseFloat(getComputedStyle(table).minWidth) || 0,
                content: document.querySelector('.fi-page-content').getBoundingClientRect().width,
                pageScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };
        JS, [$selector]);
    }
}
