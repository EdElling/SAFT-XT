<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Apps\CurrentApp;
use App\Dashboard\DashboardLayout;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Report;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Auswertungen (Bereich RPT). */
class ReportsTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    /** Chart.js-Instanz über Filaments Alpine-Komponente (Chart.js ist nicht global). */
    private const string CHART = 'const el = document.querySelector("[data-chart-type=bar]"); const chart = el && window.Alpine ? window.Alpine.$data(el).getChart() : null;';

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin();

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
        ]);
        $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
        ]);

        $acme = DynamicRecord::forTableName('kunden')->create(['name' => 'ACME']);
        $beta = DynamicRecord::forTableName('kunden')->create(['name' => 'Beta']);

        foreach ([['B-1', $acme, '100.00'], ['B-2', $acme, '50.50'], ['B-3', $beta, '1200.00']] as [$nr, $kunde, $betrag]) {
            DynamicRecord::forTableName('bestellungen')->create(['nummer' => $nr, 'kunde_id' => $kunde->id, 'betrag' => $betrag]);
        }
    }

    /** RPT-01 */
    public function test_designer_with_plus_menu_drag_and_drop_and_live_preview(): void
    {
        $ref = fn (string $table, string $field, array $path = []): string => implode('/', $path).':'.$this->fieldIdOf($table, $field);
        $relation = $this->fieldIdOf('bestellungen', 'kunde_id');

        $this->browse(function (Browser $browser) use ($ref, $relation): void {
            $this->login($browser, self::ADMIN)->visit('/admin/reports/create')
                ->waitFor('[data-report-designer]', 15)
                ->click('[data-base-picker] > button')
                ->waitFor('[data-base-filter]', 5)
                ->type('[data-base-filter]', 'bestell')
                ->pause(300)
                ->assertVisible('[data-base-option="'.CustomTable::query()->where('name', 'bestellungen')->value('id').'"][data-kind="internal"]')
                ->assertMissing('[data-base-option="'.CustomTable::query()->where('name', 'kunden')->value('id').'"]')
                ->click('[data-base-option="'.CustomTable::query()->where('name', 'bestellungen')->value('id').'"]')
                ->waitFor('[data-zone="catalog"]', 15)
                ->assertSeeIn('[data-base-selected]', 'Bestellungen');

            // Über das ＋-Menü (Tastatur/Screenreader): Kunde › Name unter „Zeilen“.
            $kunde = $ref('kunden', 'name', [$relation]);
            $browser->click("[data-zone=\"catalog\"] [data-ref=\"{$kunde}\"] button")->pause(400);
            $this->clickVisibleButton($browser, 'Spalten der Liste')->waitForText('Liste: Kunde › Name', 15);

            // Ziehen und Ablegen: Betrag in „Werte“ – die Vorschau gruppiert und summiert sofort.
            $browser->drag("[data-zone=\"catalog\"] [data-ref=\"{$ref('bestellungen', 'betrag')}\"]", '[data-zone="values"]')
                ->waitForText('Summe Betrag je Kunde › Name', 15)
                ->assertSeeIn('[data-report-table]', '150,50')
                ->assertSeeIn('[data-report-table]', '1.200,00');

            $browser->click('[data-visual="bar"]')->waitFor('[data-chart-type="bar"] canvas', 15);

            $browser->type('input[id="form.name"]', 'Umsatz je Kunde');
            $browser->script('window.scrollTo(0, 0);');
            $this->clickVisibleButton($browser->pause(300), 'Speichern')
                ->waitForText('Summe Betrag je Kunde › Name', 15)
                ->assertPathBeginsWith('/admin/reports/')
                ->waitFor('[data-chart-type="bar"] canvas', 15);
        });

        $this->assertSame('bar', Report::query()->where('name', 'Umsatz je Kunde')->sole()->definition['visual']['type']);
    }

    /** RPT-20 */
    public function test_system_fields_group_by_person_with_names(): void
    {
        $admin = User::query()->where('email', self::ADMIN)->sole();
        auth()->login($admin);
        DynamicRecord::forTableName('bestellungen')->create(['nummer' => 'B-9', 'betrag' => '7.00']);
        auth()->logout();

        $this->browse(function (Browser $browser) use ($admin): void {
            $this->login($browser, self::ADMIN)->visit('/admin/reports/create')
                ->waitFor('[data-report-designer]', 15)
                ->click('[data-base-picker] > button')
                ->waitFor('[data-base-filter]', 5)
                ->click('[data-base-option="'.CustomTable::query()->where('name', 'bestellungen')->value('id').'"]')
                ->waitFor('[data-zone="catalog"]', 15)
                // Systemspalten stehen je Tabelle am Ende der Feldliste, auch bei der verknüpften Tabelle.
                ->assertPresent('[data-zone="catalog"] [data-ref=":created_at"]')
                ->assertPresent('[data-zone="catalog"] [data-ref="'.$this->fieldIdOf('bestellungen', 'kunde_id').':updated_by"]');

            $browser->click('[data-zone="catalog"] [data-ref=":created_by"] button')->pause(400);
            $this->clickVisibleButton($browser, 'Spalten der Liste')->waitForText('Liste: Angelegt von', 15);

            $browser->drag('[data-zone="catalog"] [data-ref=":'.$this->fieldIdOf('bestellungen', 'betrag').'"]', '[data-zone="values"]')
                ->waitForText('Summe Betrag je Angelegt von', 15)
                ->assertSeeIn('[data-report-table]', $admin->name)
                ->assertSeeIn('[data-report-table]', '7,00')
                ->assertSeeIn('[data-report-table]', '1.350,50');
        });
    }

    /** RPT-15 */
    public function test_picker_greys_out_unlinked_tables_until_the_selection_is_cleared(): void
    {
        app(DynamicSchemaService::class)->createTable(['name' => 'notizen', 'label' => 'Notizen'], [['name' => 'text', 'label' => 'Text', 'type' => 'string']]);
        $id = fn (string $name): int => (int) CustomTable::query()->where('name', $name)->value('id');

        $this->browse(function (Browser $browser) use ($id): void {
            $open = fn (): Browser => $browser->click('[data-base-picker] > button')->waitFor('[data-base-filter]', 5);

            $this->login($browser, self::ADMIN)->visit('/admin/reports/create')->waitFor('[data-report-designer]', 15);
            $open()->assertMissing('[data-base-reset]')
                ->click('[data-base-option="'.$id('bestellungen').'"]')
                ->waitFor('[data-zone="catalog"]', 15);

            $open()->assertSee('NICHT VERKNÜPFT MIT „BESTELLUNGEN“')
                ->assertAttribute('[data-base-option="'.$id('notizen').'"]', 'disabled', 'true')
                ->assertEnabled('[data-base-option="'.$id('kunden').'"]')
                ->click('[data-base-option="'.$id('notizen').'"]')
                ->pause(400)
                ->assertSeeIn('[data-base-selected]', 'Bestellungen')
                ->click('[data-base-reset]')
                ->waitUntilMissing('[data-zone="catalog"]', 15);

            $open()->assertEnabled('[data-base-option="'.$id('notizen').'"]')
                ->click('[data-base-option="'.$id('notizen').'"]')
                ->waitFor('[data-zone="catalog"]', 15)
                ->assertSeeIn('[data-base-selected]', 'Notizen');
        });
    }

    /** RPT-02 */
    public function test_bar_chart_is_drawn(): void
    {
        $field = fn (string $table, string $name): int => (int) CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $name)->value('id');
        $report = Report::create([
            'name' => 'Umsatz je Kunde',
            'owner_id' => 1,
            'definition' => [
                'base' => (int) CustomTable::query()->where('name', 'bestellungen')->value('id'),
                'fields' => [
                    ['key' => 'k', 'path' => [$field('bestellungen', 'kunde_id')], 'field' => $field('kunden', 'name')],
                    ['key' => 'b', 'path' => [], 'field' => $field('bestellungen', 'betrag')],
                ],
                'groups' => ['k'],
                'measures' => [['key' => 's', 'field' => 'b', 'agg' => 'sum']],
                'visual' => ['type' => 'bar'],
            ],
        ]);

        $this->browse(function (Browser $browser) use ($report): void {
            $this->login($browser, self::ADMIN)->visit("/admin/reports/{$report->id}")
                ->waitForText('Umsatz je Kunde', 15)
                ->waitFor('[data-chart-type="bar"] canvas', 15)
                ->waitUsing(15, 200, fn (): bool => (bool) $browser->script(self::CHART.' return !!chart;')[0]);

            $labels = $browser->script(self::CHART.' return chart.data.labels;')[0];
            $this->assertSame(['ACME', 'Beta'], $labels);
        });
    }

    /** RPT-10 */
    public function test_pin_report_to_my_dashboard(): void
    {
        $field = fn (string $table, string $name): int => $this->fieldIdOf($table, $name);
        $report = Report::create([
            'name' => 'Umsatz je Kunde',
            'owner_id' => 1,
            'definition' => [
                'base' => (int) CustomTable::query()->where('name', 'bestellungen')->value('id'),
                'fields' => [
                    ['key' => 'k', 'path' => [$field('bestellungen', 'kunde_id')], 'field' => $field('kunden', 'name')],
                    ['key' => 'b', 'path' => [], 'field' => $field('bestellungen', 'betrag')],
                ],
                'groups' => ['k'],
                'measures' => [['key' => 's', 'field' => 'b', 'agg' => 'sum']],
                'visual' => ['type' => 'pie'],
            ],
        ]);

        $this->browse(function (Browser $browser) use ($report): void {
            $this->login($browser, self::ADMIN)->visit("/admin/reports/{$report->id}")
                ->waitForText('Umsatz je Kunde', 15);
            $this->clickVisibleButton($browser, 'Auf mein Dashboard')->waitForText('Auf dem Dashboard', 15);

            // Widgets weiter unten lädt Filament erst, wenn sie ins Bild scrollen.
            $browser->visit('/admin')->waitFor('[data-dashboard-column]', 15)->pause(500);
            $browser->script('window.scrollTo(0, document.body.scrollHeight);');
            $browser->pause(800)->script('window.scrollTo(0, document.body.scrollHeight);');
            $browser->waitFor('[data-dashboard-report]', 20)
                ->assertSeeIn('[data-dashboard-report]', 'Umsatz je Kunde')
                ->waitFor('[data-dashboard-report] [data-chart-type="pie"] canvas', 15)
                ->waitUsing(15, 200, fn (): bool => (bool) $browser->script('const el = document.querySelector("[data-dashboard-report] [data-chart-type=pie]"); return !!(el && window.Alpine && window.Alpine.$data(el).getChart());')[0]);

            // Diagramm standardmäßig halb breit; in den breiten Bereich gezogen wird es breit (DSH-11).
            $key = 'report:'.$report->id;
            $browser->assertPresent('[data-dashboard-column="left"] [data-dashboard-card="'.$key.'"], [data-dashboard-column="right"] [data-dashboard-card="'.$key.'"]');
            $browser->script('window.scrollTo(0, 0);');
            $this->clickVisibleButton($browser->pause(300), 'Dashboard anpassen')->waitFor('[data-dashboard-editor]', 15);
            $this->dragInSteps($browser, '[data-dashboard-card="'.$key.'"] [data-dashboard-handle]', '[data-dashboard-column="full"] [data-dashboard-card="RecentChangesWidget"]');
            $stored = static function (): array {
                $admin = User::query()->where('email', self::ADMIN)->sole();

                return (array) DashboardLayout::forApp($admin->dashboard_layout, (string) (app(CurrentApp::class)->id($admin) ?? 'none'));
            };
            $browser->waitUsing(15, 200, fn (): bool => in_array($key, (array) ($stored()['full'] ?? []), true))
                ->assertPresent('[data-dashboard-column="full"] [data-dashboard-card="'.$key.'"]');
        });
    }

    private function fieldIdOf(string $table, string $field): int
    {
        return (int) CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $field)->value('id');
    }

    /** Option eines nach Tabellen gruppierten Auswahlfelds (nicht der Gruppen-Container). */
    private function pickGroupedOption(Browser $browser, string $buttonId, string $text): void
    {
        $option = "//button[@id='{$buttonId}']/ancestor::*[contains(@class, 'fi-select-input-ctn')][1]"
            ."//*[contains(concat(' ', normalize-space(@class), ' '), ' fi-select-input-option ')][normalize-space(.)='{$text}']";

        $browser->click("button[id=\"{$buttonId}\"]")->pause(400);
        $browser->driver->executeScript('document.evaluate(arguments[0], document, null, 9, null).singleNodeValue?.scrollIntoView({block: "center"});', [$option]);
        $browser->clickAtXPath($option)->pause(600);
    }

    /** ID des Auswahlfelds in der zuletzt hinzugefügten Repeater-Zeile. */
    private function lastRepeaterField(Browser $browser, string $repeater, string $field): string
    {
        return (string) $browser->script(
            'const all = document.querySelectorAll(\'button[id^="form.'.$repeater.'."][id$=".'.$field.'"]\'); return all[all.length - 1].id;',
        )[0];
    }
}
