<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataPermissions;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\Browser\Concerns\CreatesOrderData;
use Tests\DuskTestCase;

/** Master-Detail-Kaskade im echten Browser (Bereich KAS). */
class CascadeTest extends DuskTestCase
{
    use CreatesOrderData;

    private const string ADMIN = 'admin@example.com';

    private const string SECOND_TAB = "(//*[contains(@class, 'fi-tabs-item')][@data-tab-key])[2]";

    private function openCustomers(Browser $browser): Browser
    {
        return $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
    }

    private function urlQuery(Browser $browser): string
    {
        return (string) parse_url($browser->driver->getCurrentURL(), PHP_URL_QUERY);
    }

    private function applyTextFilter(Browser $browser, int $table, string $field, string $value): Browser
    {
        $input = "tableFiltersForm.{$field}.value";

        return $browser
            ->clickAtXPath("(//button[@aria-label='Filtern'])[{$table}]")
            ->waitFor("input[id=\"{$input}\"]", 15)
            ->type("input[id=\"{$input}\"]", $value)
            ->clickAtXPath("//input[@id='{$input}']/ancestor::div[contains(@class, 'fi-ta-filters')][1]//button[contains(., 'nwenden')]");
    }

    private function firstRowText(Browser $browser, int $table): string
    {
        return $browser->driver
            ->findElement(WebDriverBy::xpath("(//table[contains(@class, 'fi-ta-table')])[{$table}]//tr[contains(@class, 'fi-ta-row')][1]"))
            ->getText();
    }

    /** KAS-02 */
    public function test_same_order_shows_same_positions_from_both_entry_points(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $this->clickRow($browser, 'B-100')->waitForText('Schraube M8', 15);

            $browser->visit('/admin/data/bestellungen')->waitForText('B-100', 15)->assertDontSee('Schraube M8');
            $this->clickRow($browser, 'B-100')->waitForText('Schraube M8', 15)->assertDontSee('Mutter M8');
        });
    }

    /** KAS-03 */
    public function test_highlight_follows_the_clicked_row_on_each_level(): void
    {
        $this->superAdmin();
        $this->createOrderData();
        DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-201', 'kunde_id' => $this->beta->id]);

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);

            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $this->assertCount(1, $this->selectedRows($browser));
            $this->assertStringContainsString('ACME GmbH', $this->selectedRows($browser)[0]);

            $this->clickRow($browser, 'Beta AG')->waitForText('B-200', 15);
            $this->assertCount(1, $this->selectedRows($browser));
            $this->assertStringContainsString('Beta AG', $this->selectedRows($browser)[0]);

            $this->clickRow($browser, 'B-200')->waitForText('Mutter M8', 15);
            $this->clickRow($browser, 'B-201')->waitUntilMissingText('Mutter M8', 15);

            $selected = $this->selectedRows($browser);
            $this->assertCount(2, $selected);
            $this->assertStringContainsString('Beta AG', $selected[0]);
            $this->assertStringContainsString('B-201', $selected[1]);
        });
    }

    /** KAS-04 */
    public function test_clicking_the_selected_row_again_removes_selection_and_details(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $this->clickRow($browser, 'B-100')->waitForText('Schraube M8', 15);

            $this->clickRow($browser, 'B-100')->waitUntilMissingText('Schraube M8', 15);
            $this->assertCount(1, $this->selectedRows($browser));
            $browser->assertSee('B-100');

            $this->clickRow($browser, 'ACME GmbH')->waitUntilMissingText('B-100', 15);
            $this->assertSame([], $this->selectedRows($browser));
            $this->assertStringNotContainsString('sel=', $this->urlQuery($browser));
        });
    }

    /** KAS-05 */
    public function test_selecting_another_row_replaces_all_deeper_levels(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $this->clickRow($browser, 'B-100')->waitForText('Schraube M8', 15);

            $this->clickRow($browser, 'Beta AG')
                ->waitForText('B-200', 15)
                ->assertDontSee('B-100')
                ->assertDontSee('Schraube M8')
                ->assertDontSee('Mutter M8');
        });
    }

    /** KAS-06 */
    public function test_top_list_keeps_filter_sort_and_page_after_selection(): void
    {
        $this->superAdmin();
        $this->createOrderData(extraCustomers: 30);

        $this->browse(function (Browser $browser): void {
            $browser = $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Zusatz 30', 15);

            $this->applyTextFilter($browser, 1, 'ort', 'Köln')->waitUntilMissingText('ACME GmbH', 15);
            $browser->click('th.fi-ta-header-cell-firma button')->waitForTextIn('tr.fi-ta-row', 'Zusatz 01', 15);
            // 10 Zeilen je Seite: bis zur letzten Seite blättern.
            $this->clickVisible($browser, '[rel="next"]')->waitForTextIn('.fi-pagination-overview', '11 bis 20', 15);
            $this->clickVisible($browser, '[rel="next"]')->waitForTextIn('.fi-pagination-overview', '21 bis 30', 15);

            $this->clickRow($browser, 'Zusatz 28')->waitFor('tr.saft-row-selected', 15);

            $browser->assertSeeIn('.fi-pagination-overview', '21 bis 30 von 30')
                ->assertSee('Ort: Köln')
                ->assertDontSee('ACME GmbH');
            $this->assertStringContainsString('Zusatz 21', $this->firstRowText($browser, 1));
        });
    }

    /** KAS-07 */
    public function test_detail_table_sorts_filters_and_pages_on_its_own(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        for ($number = 101; $number <= 112; $number++) {
            DynamicRecord::forTable($this->bestellungen)->create(['nummer' => "B-{$number}", 'kunde_id' => $this->acme->id]);
        }

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15)->assertDontSee('B-112');

            $this->clickVisible($browser, '[rel="next"]')->waitForText('B-112', 15)->assertDontSee('B-100');

            // Sortieren springt auf Seite 1 der Detailtabelle.
            $browser->click('th.fi-ta-header-cell-nummer button')
                ->waitUsing(15, 100, fn (): bool => str_contains($this->firstRowText($browser, 2), 'B-100'));
            $browser->click('th.fi-ta-header-cell-nummer button')
                ->waitUsing(15, 100, fn (): bool => str_contains($this->firstRowText($browser, 2), 'B-112'));

            $this->applyTextFilter($browser, 2, 'nummer', 'B-105')->waitUntilMissingText('B-112', 15);
            $browser->waitForText('B-105', 15)->assertSee('Beta AG');

            $this->assertCount(1, $this->selectedRows($browser));
            $this->assertStringContainsString('ACME GmbH', $this->selectedRows($browser)[0]);
        });
    }

    /** KAS-08 */
    public function test_copied_url_restores_the_selection_in_a_new_session(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $first, Browser $second): void {
            $this->openCustomers($first);
            $this->clickRow($first, 'ACME GmbH')->waitForText('B-100', 15);
            $this->clickRow($first, 'B-100')->waitForText('Schraube M8', 15);
            $url = $first->driver->getCurrentURL();

            $this->login($second, self::ADMIN);
            $second->driver->get($url);
            $second->waitForText('Schraube M8', 15);

            $this->assertSame($this->selectedRows($first), $this->selectedRows($second));
            $this->assertCount(2, $this->selectedRows($second));
        });
    }

    /** KAS-09 */
    public function test_selection_is_restored_after_leaving_the_page_and_going_back(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $this->clickRow($browser, 'B-100')->waitForText('Schraube M8', 15);

            $browser->clickLink('Benutzer und Zugriff')->waitForLocation('/admin/users', 15)
                ->clickLink('Rollen')->waitForLocation('/admin/roles', 15)
                ->back()
                ->back()
                ->waitForText('Schraube M8', 15);

            $this->assertCount(2, $this->selectedRows($browser));
        });
    }

    /** KAS-10 */
    public function test_shared_link_shows_only_permitted_parts(): void
    {
        $this->createOrderData();
        $this->activeUser('leser@example.com')->givePermissionTo(DataPermissions::name('kunden', 'view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $selection = $this->acme->id.','.$this->fieldId($this->bestellungen, 'kunde_id').':'.$this->orderAcme->id;

        $this->browse(function (Browser $browser) use ($selection): void {
            $this->login($browser, 'leser@example.com')
                ->visit('/admin/data/kunden?sel='.$selection)
                ->waitForText('ACME GmbH', 15)
                ->assertPresent(self::SELECTOR_SIDEBAR)
                ->assertDontSee('B-100')
                ->assertDontSee('Schraube M8')
                ->assertDontSee('Keine Berechtigung');

            $this->assertCount(1, $this->selectedRows($browser));
        });
    }

    /** KAS-11 */
    public function test_manipulated_selection_in_the_url_is_ignored_and_removed(): void
    {
        $this->superAdmin();
        $this->createOrderData();
        $acme = $this->acme->id;

        $this->browse(function (Browser $browser) use ($acme): void {
            $this->login($browser, self::ADMIN);

            foreach (['abc', '999999', '99999999999999999999'] as $invalid) {
                $browser->visit('/admin/data/kunden?sel='.$invalid)
                    ->waitForText('ACME GmbH', 15)
                    ->assertPresent(self::SELECTOR_SIDEBAR)
                    ->waitUsing(15, 100, fn (): bool => ! str_contains($this->urlQuery($browser), 'sel='));
                $this->assertSame([], $this->selectedRows($browser), $invalid);
            }

            $browser->visit("/admin/data/kunden?sel={$acme},99999:1")
                ->waitForText('B-100', 15)
                ->waitUsing(15, 100, fn (): bool => ! str_contains($this->urlQuery($browser), '99999'));
            $this->assertCount(1, $this->selectedRows($browser));
            $this->assertStringContainsString("sel={$acme}", $this->urlQuery($browser));
        });
    }

    /** KAS-12 */
    public function test_two_relations_appear_as_two_switchable_tabs(): void
    {
        $this->superAdmin();
        $this->createOrderData(secondRelation: true);
        DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-300', 'kunde_id' => $this->beta->id, 'rechnung_an_id' => $this->acme->id]);

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);

            $this->assertSame(
                ['Bestellungen (über „Kunde“)', 'Bestellungen (über „Rechnung an“)'],
                array_map(static fn ($tab): string => trim($tab->getText()), $browser->elements('.fi-tabs-item')),
            );
            $this->assertCount(1, $browser->elements('.fi-tabs-item.fi-active'));

            $browser->assertDontSee('B-300')
                ->clickAtXPath(self::SECOND_TAB)
                ->waitForText('B-300', 15)
                ->assertDontSee('B-100');
        });
    }

    /** KAS-13 */
    public function test_selection_in_second_tab_opens_next_level_and_survives_reload(): void
    {
        $this->superAdmin();
        $this->createOrderData(secondRelation: true);
        $order = DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-300', 'kunde_id' => $this->beta->id, 'rechnung_an_id' => $this->acme->id]);
        DynamicRecord::forTable($this->positionen)->create(['artikel' => 'Scheibe M8', 'bestellung_id' => $order->id]);

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $browser->clickAtXPath(self::SECOND_TAB)->waitForText('B-300', 15);

            $this->clickRow($browser, 'B-300')->waitForText('Scheibe M8', 15);
            $this->assertCount(2, $this->selectedRows($browser));

            $browser->refresh()->waitForText('Scheibe M8', 15)->assertSee('B-300');
            $this->assertCount(2, $this->selectedRows($browser));
            $this->assertStringContainsString('B-300', $this->selectedRows($browser)[1]);
            $browser->assertSeeIn('.fi-tabs-item.fi-active', 'Rechnung an')->assertDontSee('B-100');
        });
    }

    /** Klick in einer bestimmten Tabelle, wenn derselbe Text auch weiter oben steht. */
    private function clickRowInTable(Browser $browser, int $table, string $text): Browser
    {
        return $browser->clickAtXPath("(//table[contains(@class, 'fi-ta-table')])[{$table}]//tr[contains(@class, 'fi-ta-row')][contains(., '{$text}')]//td[contains(@class, 'fi-ta-cell')][1]");
    }

    /** KAS-14 */
    public function test_cascade_stops_after_four_levels(): void
    {
        $this->superAdmin();
        $schema = app(DynamicSchemaService::class);
        $previous = null;
        $parent = null;

        foreach (range(1, 5) as $level) {
            $fields = [['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true]];

            if ($previous !== null) {
                $fields[] = ['name' => 'oben_id', 'label' => 'Oben', 'type' => 'relation', 'options' => ['table' => $previous->name, 'display_field' => 'titel']];
            }

            $table = $schema->createTable(['name' => "stufe{$level}", 'label' => "Stufe {$level}"], $fields);
            $parent = DynamicRecord::forTable($table)->create(['titel' => "Satz {$level}", ...($parent !== null ? ['oben_id' => $parent->id] : [])]);

            if ($previous !== null) {
                $schema->updateTable($previous, ['detail_field_ids' => [$this->fieldId($table, 'oben_id')]]);
            }

            $previous = $table->fresh();
        }

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/stufe1')->waitForText('Satz 1', 15);

            foreach ([1 => 'Satz 2', 2 => 'Satz 3', 3 => 'Satz 4'] as $level => $next) {
                $this->clickRow($browser, "Satz {$level}")->waitForText($next, 15);
            }

            $this->clickRow($browser, 'Satz 4')->waitFor('tr.saft-row-selected', 15)->pause(1200);

            $this->assertCount(4, $this->selectedRows($browser));
            $browser->assertDontSee('Satz 5')->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** KAS-15 */
    public function test_self_relation_shows_children_level_by_level(): void
    {
        $this->superAdmin();
        $schema = app(DynamicSchemaService::class);
        $table = $schema->createTable(['name' => 'kategorien', 'label' => 'Kategorien'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
        ]);
        $schema->addField($table, ['name' => 'eltern_id', 'label' => 'Eltern', 'type' => 'relation', 'options' => ['table' => 'kategorien', 'display_field' => 'titel']]);
        $schema->updateTable($table, ['detail_field_ids' => [$this->fieldId($table, 'eltern_id')]]);
        DynamicRecord::forget('kategorien');
        $top = DynamicRecord::forTableName('kategorien')->create(['titel' => 'Oberste']);
        $middle = DynamicRecord::forTableName('kategorien')->create(['titel' => 'Mittlere', 'eltern_id' => $top->id]);
        DynamicRecord::forTableName('kategorien')->create(['titel' => 'Unterste', 'eltern_id' => $middle->id]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kategorien')->waitForText('Oberste', 15);

            $this->clickRowInTable($browser, 1, 'Oberste')->waitUsing(15, 100, fn (): bool => count($browser->elements('table.fi-ta-table')) === 2);
            $this->assertStringContainsString('Mittlere', $this->firstRowText($browser, 2));

            $this->clickRowInTable($browser, 2, 'Mittlere')->waitUsing(15, 100, fn (): bool => count($browser->elements('table.fi-ta-table')) === 3);
            $this->assertStringContainsString('Unterste', $this->firstRowText($browser, 3));
            $this->assertCount(2, $this->selectedRows($browser));
            $browser->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** KAS-16 */
    public function test_summary_shows_formatted_table_and_required_fields(): void
    {
        $this->superAdmin();
        $this->createOrderData();
        $schema = app(DynamicSchemaService::class);
        $schema->addField($this->bestellungen, ['name' => 'datum', 'label' => 'Datum', 'type' => 'date']);
        $schema->addField($this->bestellungen, ['name' => 'intern', 'label' => 'Intern', 'type' => 'string', 'show_in_table' => false]);
        DynamicRecord::forget('bestellungen');
        DynamicRecord::forTableName('bestellungen')->newQuery()->findOrFail($this->orderAcme->id)
            ->update(['datum' => '2026-02-01', 'intern' => 'Nur im Popup']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/bestellungen')->waitForText('B-100', 15);
            $this->clickRow($browser, 'B-100')->waitForText('Bestellungen: B-100', 15);

            $summary = $browser->driver->findElement(WebDriverBy::xpath("//*[contains(@class, 'fi-section')][contains(., 'Bestellungen: B-100')]"))->getText();

            foreach (['Nummer', 'B-100', 'Kunde', 'ACME GmbH', '01.02.2026'] as $expected) {
                $this->assertStringContainsString($expected, $summary);
            }

            $this->assertStringNotContainsString('Nur im Popup', $summary);
        });
    }

    /** KAS-17 */
    public function test_details_button_does_not_select_the_row(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->openDetails($browser, 'ACME GmbH')->pause(500);

            $this->assertSame([], $this->selectedRows($browser));
            $this->assertStringNotContainsString('sel=', $this->urlQuery($browser));
        });
    }

    /** KAS-18 */
    public function test_new_record_in_detail_table_belongs_to_the_selected_parent(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $this->clickRow($browser, 'B-100')->waitForText('Schraube M8', 15);

            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="mountedActionSchema0.artikel"]', 15)
                ->assertMissing('[id="mountedActionSchema0.bestellung_id"]')
                ->type('input[id="mountedActionSchema0.artikel"]', 'Neue Position');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Neue Position', 15)->assertSee('Schraube M8');
        });

        $created = DynamicRecord::forTable($this->positionen)->newQuery()->where('artikel', 'Neue Position')->firstOrFail();
        $this->assertSame((int) $this->orderAcme->id, (int) $created->getAttribute('bestellung_id'));
    }

    /** KAS-19 */
    public function test_deleting_the_selected_record_removes_its_selection(): void
    {
        $this->superAdmin();
        $this->createOrderData();
        DynamicRecord::forTable($this->positionen)->newQuery()->delete();

        $this->browse(function (Browser $browser): void {
            $this->openCustomers($browser);
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $this->clickRow($browser, 'B-100')->waitForText('Bestellungen: B-100', 15);

            $this->mobile($browser)->clickAtXPath("(//table[contains(@class, 'fi-ta-table')])[2]//tr[contains(., 'B-100')]//button[contains(., 'Details')]")
                ->waitForText('Schliessen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')->waitUntilMissingText('Bestellungen: B-100', 15);

            // Die Detailtabelle wird nach dem Löschen neu gezeichnet: warten, bis nur noch der Kunde markiert ist.
            $browser->waitUsing(15, 100, fn (): bool => count($this->selectedRows($browser)) === 1);
            $this->assertCount(1, $this->selectedRows($browser));
            $this->assertStringContainsString('sel='.$this->acme->id, $this->urlQuery($browser));
            $browser->waitUsing(15, 100, fn (): bool => ! str_contains(urldecode($this->urlQuery($browser)), ':'.$this->orderAcme->id));
            $browser->assertDontSee('B-100');
        });

        $this->assertTrue($this->orderAcme->fresh()->trashed());
    }

    /** KAS-20 */
    public function test_no_new_child_under_a_deleted_parent(): void
    {
        $this->superAdmin();
        $this->createOrderData();
        $weg = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Weg GmbH']);
        $weg->delete();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden?sel='.$this->acme->id)->waitForText('B-100', 15);
            $visible = fn (): int => count(array_filter(
                $browser->driver->findElements(WebDriverBy::xpath("//button[normalize-space(.)='Neuer Datensatz']")),
                static fn ($button): bool => $button->isDisplayed(),
            ));
            $this->assertSame(2, $visible());

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->waitFor('select[id="tableFiltersForm.trashed.value"]', 15)->select('select[id="tableFiltersForm.trashed.value"]', '0');
            })->waitForText('Weg GmbH', 15);

            $this->clickRow($browser, 'Weg GmbH')->waitForText('Kunden: Weg GmbH', 15)->waitForText('Keine zugeordneten Datensätze', 15);
            $this->assertSame(1, $visible());
        });

        $this->assertSame(2, DynamicRecord::forTable($this->bestellungen)->newQuery()->count());
        $this->assertTrue($weg->fresh()->trashed());
    }
}
