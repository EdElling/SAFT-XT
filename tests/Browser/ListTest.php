<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\UserTableColumn;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\DuskTestCase;

/** Datentabelle: Liste (Bereich LIS). */
class ListTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string PER_PAGE = '.fi-pagination-records-per-page-select:not(.fi-compact) select';

    private CustomTable $kunden;

    private CustomTable $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $schema = app(DynamicSchemaService::class);
        $this->kunden = $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string'],
        ]);
        $this->orders = $schema->createTable(['name' => 'auftraege', 'label' => 'Aufträge'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'a_offen', 'label' => 'Offen'], ['value' => 'b_fertig', 'label' => 'Fertig']]]],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'text'],
        ]);

        app(DataMenu::class)->ensure($this->kunden);
        app(DataMenu::class)->ensure($this->orders);
    }

    /** Aufträge mit Reihenfolgen, die sich je Spalte unterscheiden. */
    private function seedOrders(): void
    {
        $zeta = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Zeta GmbH', 'ort' => 'Köln']);
        $alpha = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Alpha AG', 'ort' => 'Bonn']);
        $mitte = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Müller KG', 'ort' => 'Köln']);

        foreach ([
            ['A-3', $zeta, 9, '2026-03-15', 'a_offen'],
            ['A-1', $mitte, 100, '2026-01-20', 'b_fertig'],
            ['A-2', $alpha, 10, '2025-12-31', 'a_offen'],
        ] as [$nummer, $kunde, $menge, $datum, $status]) {
            DynamicRecord::forTable($this->orders)->create([
                'nummer' => $nummer, 'kunde_id' => $kunde->id, 'menge' => $menge, 'datum' => $datum, 'status' => $status,
            ]);
        }
    }

    /**
     * Wartet auf die vollständige Reihenfolge: Die erste Zeile allein kann schon vor dem Sortieren stimmen.
     *
     * @param  list<string>  $expected
     */
    private function sortBy(Browser $browser, string $column, array $expected): void
    {
        $browser->click("th.fi-ta-header-cell-{$column} button")
            ->waitUsing(15, 100, fn (): bool => $this->firstColumn($browser) === $expected);

        $this->assertSame($expected, $this->firstColumn($browser));
    }

    /** LIS-01 */
    public function test_sorting_is_correct_for_each_column_type(): void
    {
        $this->seedOrders();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/auftraege')->waitForText('A-1', 15);

            $this->sortBy($browser, 'nummer', ['A-1', 'A-2', 'A-3']);
            $this->sortBy($browser, 'nummer', ['A-3', 'A-2', 'A-1']);

            // Verknüpfung nach dem Anzeigenamen, nicht nach der ID.
            $this->sortBy($browser, 'kunde-id-label', ['A-2', 'A-1', 'A-3']);
            $this->sortBy($browser, 'kunde-id-label', ['A-3', 'A-1', 'A-2']);

            // Zahlen numerisch: 9 vor 10 vor 100.
            $this->sortBy($browser, 'menge', ['A-3', 'A-2', 'A-1']);
            $this->sortBy($browser, 'menge', ['A-1', 'A-2', 'A-3']);

            // Datum chronologisch, nicht nach deutscher Schreibweise.
            $this->sortBy($browser, 'datum', ['A-2', 'A-1', 'A-3']);
            $this->sortBy($browser, 'datum', ['A-3', 'A-1', 'A-2']);

            $browser->click('th.fi-ta-header-cell-status button')->waitUsing(15, 100, fn (): bool => ($this->firstColumn($browser)[2] ?? null) === 'A-1');
            $browser->click('th.fi-ta-header-cell-status button')->waitUsing(15, 100, fn (): bool => ($this->firstColumn($browser)[0] ?? null) === 'A-1');
        });
    }

    /** LIS-02 */
    public function test_filters_combine_show_indicators_and_can_be_reset(): void
    {
        $this->seedOrders();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/auftraege')->waitForText('A-1', 15);

            $this->applyFilters($browser, function (Browser $b): void {
                $this->pickOption($b, 'tableFiltersForm.status.values', 'Offen');
                $b->type('input[id="tableFiltersForm.nummer.value"]', 'A-2');
            });
            $browser->waitUntilMissingText('A-3', 15)->assertSee('Nummer: A-2')->assertSee('Status: Offen');
            $this->assertSame(['A-2'], $this->firstColumn($browser));

            // Einen Filter entfernen: Der andere bleibt aktiv.
            $browser->clickAtXPath("//*[contains(@class, 'fi-ta-filter-indicators')]//*[contains(., 'Nummer: A-2')]//button")
                ->waitForText('A-3', 15)
                ->assertDontSee('A-1');
            $this->assertEqualsCanonicalizing(['A-2', 'A-3'], $this->firstColumn($browser));

            $this->resetFilters($browser)->waitForText('A-1', 15);
            $this->assertCount(3, $this->firstColumn($browser));
        });
    }

    /** LIS-03 */
    public function test_range_filter_includes_its_limits_and_ignores_empty_limits(): void
    {
        $this->seedOrders();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/auftraege')->waitForText('A-1', 15);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->type('input[id="tableFiltersForm.menge.from"]', '10')->type('input[id="tableFiltersForm.menge.until"]', '10');
            });
            $browser->waitUntilMissingText('A-1', 15);
            $this->assertSame(['A-2'], $this->firstColumn($browser));

            $this->applyFilters($browser, function (Browser $b): void {
                $this->emptyField($b, 'input[id="tableFiltersForm.menge.until"]');
            });
            $browser->waitForText('A-1', 15);
            $this->assertEqualsCanonicalizing(['A-1', 'A-2'], $this->firstColumn($browser));

            $this->resetFilters($browser)->waitForText('A-3', 15);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->keys('input[id="tableFiltersForm.datum.from"]', '20012026')->keys('input[id="tableFiltersForm.datum.until"]', '20012026');
            });
            $browser->waitUntilMissingText('A-3', 15);
            $this->assertSame(['A-1'], $this->firstColumn($browser));
        });
    }

    /** LIS-04 */
    public function test_search_ignores_case_handles_umlauts_and_uses_only_searchable_fields(): void
    {
        $this->seedOrders();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Müller KG', 15);

            $browser->type('.fi-ta-search-field input', 'müller')->waitUntilMissingText('Alpha AG', 15);
            $this->assertSame(['Müller KG'], $this->firstColumn($browser));

            $browser->clear('.fi-ta-search-field input')->type('.fi-ta-search-field input', 'MÜLL')->pause(1000)
                ->waitForText('Müller KG', 15);
            $this->assertSame(['Müller KG'], $this->firstColumn($browser));

            $browser->clear('.fi-ta-search-field input')->type('.fi-ta-search-field input', 'Bonn')
                ->waitUntilMissingText('Müller KG', 15);
            $this->assertSame([], $this->firstColumn($browser));
        });
    }

    /** LIS-05 */
    public function test_special_characters_in_search_and_filter_do_not_break_the_table(): void
    {
        $this->seedOrders();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Müller KG', 15);

            foreach (['%', '_', "'", '"', '\\', "'; DROP TABLE kunden; --"] as $term) {
                $browser->clear('.fi-ta-search-field input')->type('.fi-ta-search-field input', $term)->pause(900)
                    ->assertPresent('.fi-ta-table, .fi-ta-empty-state')
                    ->assertDontSee('Unerwarteter Fehler')
                    ->assertDontSee('Datenbankfehler');
            }

            $this->emptyField($browser, '.fi-ta-search-field input')->pause(900)->waitForText('Müller KG', 15);

            foreach (['%', "'", '\\'] as $term) {
                $this->applyFilters($browser, static function (Browser $b) use ($term): void {
                    $b->clear('input[id="tableFiltersForm.ort.value"]')->type('input[id="tableFiltersForm.ort.value"]', $term);
                });
                $browser->assertDontSee('Unerwarteter Fehler')->assertDontSee('Datenbankfehler');
            }

            $this->assertSame(3, DynamicRecord::forTable($this->kunden)->newQuery()->count());
        });
    }

    /** LIS-06 */
    public function test_paging_shows_every_record_exactly_once(): void
    {
        for ($number = 1; $number <= 30; $number++) {
            DynamicRecord::forTable($this->orders)->create(['nummer' => sprintf('P-%02d', $number)]);
        }

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/auftraege')->waitForText('P-30', 15)
                ->select(self::PER_PAGE, '10')
                ->waitForTextIn('.fi-pagination-overview', '1 bis 10 von 30', 15);

            // Auch die schmale Tabelle (eine Spalte) blättert mit Seitenzahlen und Pfeilen, nicht mit „Vorherige/Nächste“.
            $browser->assertVisible('.fi-pagination-items')->assertMissing('.fi-pagination-next-btn');

            $seen = $this->firstColumn($browser);
            $this->assertCount(10, $seen);

            foreach (['11 bis 20', '21 bis 30'] as $range) {
                $this->clickVisible($browser, '[rel="next"]')->waitForTextIn('.fi-pagination-overview', $range, 15);
                $seen = [...$seen, ...$this->firstColumn($browser)];
            }

            $this->assertCount(30, array_unique($seen));

            $browser->select(self::PER_PAGE, '100')
                ->waitUsing(15, 100, fn (): bool => count($this->firstColumn($browser)) === 30);
            $this->assertCount(30, $this->firstColumn($browser));
        });
    }

    /** LIS-07 */
    public function test_filter_on_later_page_shows_the_matching_records(): void
    {
        for ($number = 1; $number <= 30; $number++) {
            DynamicRecord::forTable($this->orders)->create(['nummer' => sprintf('P-%02d', $number)]);
        }

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/auftraege')->waitForText('P-30', 15);
            $this->clickVisible($browser, '[rel="next"]')->waitForTextIn('.fi-pagination-overview', '11 bis 20', 15);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->type('input[id="tableFiltersForm.nummer.value"]', 'P-07');
            });

            $browser->waitForText('P-07', 15)->assertDontSee('Noch keine Datensätze');
            $this->assertSame(['P-07'], $this->firstColumn($browser));
        });
    }

    /** LIS-08 */
    public function test_deleted_records_filter_exists_only_with_delete_right(): void
    {
        DynamicRecord::forTable($this->kunden)->create(['firma' => 'Bleibt GmbH']);
        DynamicRecord::forTable($this->kunden)->create(['firma' => 'Weg GmbH'])->delete();
        $this->activeUser('leser@example.com')->givePermissionTo(DataPermissions::name('kunden', 'view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->browse(function (Browser $admin, Browser $reader): void {
            $this->login($admin, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Bleibt GmbH', 15)->assertDontSee('Weg GmbH');
            $this->applyFilters($admin, static function (Browser $b): void {
                $b->waitFor('select[id="tableFiltersForm.trashed.value"]', 15)->select('select[id="tableFiltersForm.trashed.value"]', '0');
            });
            $admin->waitForText('Weg GmbH', 15)->assertDontSee('Bleibt GmbH');

            $this->login($reader, 'leser@example.com')->visit('/admin/data/kunden')->waitForText('Bleibt GmbH', 15)
                ->click('button[aria-label="Filtern"]')->pause(500)
                ->assertMissing('select[id="tableFiltersForm.trashed.value"]')
                ->assertDontSee('Weg GmbH');
        });
    }

    /** LIS-09 */
    public function test_menu_entry_shows_chosen_columns_and_required_fields_only(): void
    {
        $this->seedOrders();
        $entry = Menu::query()->where('route_parameters->table', 'auftraege')->firstOrFail();
        Menu::create([
            'parent_id' => $entry->parent_id,
            'label' => 'Aufträge kurz',
            'route' => DataMenu::ROUTE,
            'route_parameters' => ['table' => 'auftraege'],
            'view_config' => ['columns' => [(int) $this->orders->fields()->where('name', 'menge')->value('id')]],
            'sort_order' => 990,
            'is_active' => true,
        ]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Aufträge kurz']")
                ->waitFor('th.fi-ta-header-cell-menge', 15)
                ->assertPresent('th.fi-ta-header-cell-nummer')
                ->assertMissing('th.fi-ta-header-cell-datum')
                ->assertMissing('th.fi-ta-header-cell-status')
                ->assertSee('A-1');
        });
    }

    /** LIS-10 */
    public function test_empty_table_shows_hint_and_first_record_appears_without_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Noch keine Datensätze', 15);

            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="mountedActionSchema0.firma"]', 15);
            $browser->type('input[id="mountedActionSchema0.firma"]', 'Erste GmbH');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Erste GmbH', 15)->assertDontSee('Noch keine Datensätze');
        });
    }

    /** LIS-11 */
    public function test_wide_table_scrolls_and_details_stay_reachable(): void
    {
        $fields = [];

        foreach (range(1, 12) as $number) {
            $fields[] = ['name' => "spalte_{$number}", 'label' => "Lange Spaltenüberschrift {$number}", 'type' => 'string'];
        }

        $table = app(DynamicSchemaService::class)->createTable(['name' => 'breit', 'label' => 'Breit'], $fields);
        DynamicRecord::forTable($table)->create(['spalte_1' => 'Erste Spalte']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/breit')->waitForText('Erste Spalte', 15);

            [$scrollable] = $browser->script(<<<'JS'
                const content = document.querySelector('.fi-ta-content-ctn');
                return content.scrollWidth > content.clientWidth;
            JS);
            $this->assertTrue($scrollable);

            // Die Tabelle bleibt im Inhaltsbereich; nur sie scrollt, nicht die ganze Seite.
            [$widths] = $browser->script(<<<'JS'
                return {
                    table: document.querySelector('.fi-ta').getBoundingClientRect().width,
                    content: document.querySelector('.fi-page-content').getBoundingClientRect().width,
                    pageScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                };
            JS);
            $this->assertLessThanOrEqual($widths['content'] + 0.5, $widths['table']);
            $this->assertFalse($widths['pageScrolls']);

            $this->openDetails($browser, 'Erste Spalte')->assertSee('Bearbeiten');
        });
    }

    /** LIS-12 */
    public function test_long_text_is_cut_in_the_list_and_keeps_the_row_small(): void
    {
        $text = str_repeat("Langer Absatz mit viel Inhalt.\n", 80);
        DynamicRecord::forTable($this->orders)->create(['nummer' => 'L-1', 'notiz' => $text]);

        $this->browse(function (Browser $browser) use ($text): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/auftraege')->waitForText('L-1', 15);

            $cell = $browser->driver->findElement(WebDriverBy::cssSelector('td.fi-ta-cell-notiz'));
            $this->assertLessThan(80, mb_strlen($cell->getText()));
            $this->assertLessThan(200, $browser->element('tr.fi-ta-row')->getSize()->getHeight());

            $this->openDetails($browser, 'L-1')->waitForText('Langer Absatz', 15);
            $this->assertSame($text, DynamicRecord::forTable($this->orders)->newQuery()->where('nummer', 'L-1')->value('notiz'));
        });
    }

    /** LIS-13 */
    public function test_number_filter_tolerates_extreme_and_fractional_values(): void
    {
        $this->seedOrders();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/auftraege')->waitForText('A-1', 15);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->type('input[id="tableFiltersForm.menge.from"]', '999999999999999999999999999999');
            });
            $browser->waitUntilMissingText('A-1', 15)->assertDontSee('Datenbankfehler')->assertDontSee('Unerwarteter Fehler');
            $this->assertSame([], $this->firstColumn($browser));

            $this->applyFilters($browser, function (Browser $b): void {
                $this->emptyField($b, 'input[id="tableFiltersForm.menge.from"]');
                $b->type('input[id="tableFiltersForm.menge.until"]', '999999999999999999999999999999');
            });
            $browser->waitForText('A-1', 15);
            $this->assertCount(3, $this->firstColumn($browser));

            $this->applyFilters($browser, function (Browser $b): void {
                $this->emptyField($b, 'input[id="tableFiltersForm.menge.until"]');
                $b->type('input[id="tableFiltersForm.menge.from"]', '9.5');
            });
            $browser->waitUntilMissingText('A-3', 15)->assertDontSee('Datenbankfehler');
            $this->assertEqualsCanonicalizing(['A-1', 'A-2'], $this->firstColumn($browser));
        });
    }

    /** LIS-14 */
    public function test_narrow_table_is_only_as_wide_as_its_columns(): void
    {
        DynamicRecord::forTable($this->kunden)->create(['firma' => 'Schmal GmbH', 'ort' => 'Bern']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Schmal GmbH', 15);

            [$layout] = $browser->script(<<<'JS'
                const card = document.querySelector('.fi-ta').getBoundingClientRect();
                const details = [...document.querySelectorAll('tr.fi-ta-row button')].at(-1).getBoundingClientRect();
                return {
                    card: card.width,
                    content: document.querySelector('.fi-page-content').getBoundingClientRect().width,
                    detailsGap: card.right - details.right,
                    scrolls: document.querySelector('.fi-ta-content-ctn').scrollWidth > document.querySelector('.fi-ta-content-ctn').clientWidth,
                };
            JS);

            // Zwei Spalten füllen nicht die ganze Breite; „Details“ steht am Rand der Tabelle, nicht des Fensters.
            $this->assertLessThan($layout['content'] * 0.75, $layout['card']);
            $this->assertLessThan(60, $layout['detailsGap']);
            $this->assertFalse($layout['scrolls']);
            $browser->assertVisible('.fi-pagination-overview');
        });
    }

    /** LIS-19: Ansicht bleibt nach dem Abmelden erhalten und lässt sich zurücksetzen (B-027). */
    public function test_view_is_remembered_after_signing_out_and_can_be_reset(): void
    {
        $this->seedOrders();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Müller KG', 15)
                ->assertDontSee('Ansicht zurücksetzen');
            $browser->type('.fi-ta-search-field input', 'müller')->waitUntilMissingText('Alpha AG', 15)
                ->waitForText('Ansicht zurücksetzen', 15);

            $this->logout($browser);
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Müller KG', 15)
                ->assertInputValue('.fi-ta-search-field input', 'müller')
                ->assertDontSee('Alpha AG')
                ->press('Ansicht zurücksetzen')
                ->waitForText('Alpha AG', 15)
                ->assertInputValue('.fi-ta-search-field input', '')
                ->waitUntilMissingText('Ansicht zurücksetzen', 15);
        });
    }

    /** LIS-16 */
    public function test_column_choice_in_the_column_menu_is_remembered(): void
    {
        $this->seedOrders();
        $entry = Menu::query()->where('route_parameters->table', 'auftraege')->firstOrFail();
        Menu::create([
            'parent_id' => $entry->parent_id,
            'label' => 'Aufträge kurz',
            'route' => DataMenu::ROUTE,
            'route_parameters' => ['table' => 'auftraege'],
            'view_config' => ['columns' => [(int) $this->orders->fields()->where('name', 'menge')->value('id')]],
            'sort_order' => 990,
            'is_active' => true,
        ]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Aufträge kurz']")
                ->waitFor('th.fi-ta-header-cell-menge', 15)
                ->assertMissing('th.fi-ta-header-cell-datum');
            $url = $browser->driver->getCurrentURL();

            // Spalten-Menü: „Datum“ einschalten (ggf. mit „Anwenden“ bestätigen).
            $browser->click('.fi-ta-col-manager-dropdown .fi-dropdown-trigger button')
                ->waitFor('.fi-ta-col-manager-label', 10)
                ->clickAtXPath("//label[contains(@class, 'fi-ta-col-manager-label')][contains(normalize-space(.), 'Datum')]")
                ->pause(400);
            $browser->clickAtXPath("//div[contains(@class, 'fi-ta-col-manager')]//button[contains(normalize-space(.), 'Spalten anwenden')]")
                ->waitFor('th.fi-ta-header-cell-datum', 15);

            // Neu laden: die Auswahl bleibt (gespeichert je Benutzer, nicht nur in der Sitzung).
            $browser->visit($url)->waitFor('th.fi-ta-header-cell-menge', 15)->assertPresent('th.fi-ta-header-cell-datum');
            $this->assertSame(1, UserTableColumn::query()->count());
        });
    }
}
