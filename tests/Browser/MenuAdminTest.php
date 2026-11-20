<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use App\Support\IconCatalog;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\DuskTestCase;

/** Menü-Verwaltung (Bereich MEN). */
class MenuAdminTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private CustomTable $kunden;

    private CustomTable $artikel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $schema = app(DynamicSchemaService::class);
        $this->kunden = $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string'],
            ['name' => 'land', 'label' => 'Land', 'type' => 'string'],
        ]);
        DynamicRecord::forTable($this->kunden)->create(['firma' => 'ACME GmbH', 'ort' => 'Berlin', 'land' => 'DE']);
        $this->artikel = $schema->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'is_required' => true],
            ['name' => 'farbe', 'label' => 'Farbe', 'type' => 'string'],
        ]);
    }

    private function fieldId(CustomTable $table, string $name): int
    {
        return (int) $table->fields()->where('name', $name)->value('id');
    }

    private function column(int $fieldId): string
    {
        return "input[wire\\:model=\"data.data_columns\"][value=\"{$fieldId}\"]";
    }

    private function chooseType(Browser $browser, string $label): Browser
    {
        return $browser->clickAtXPath("//label[contains(., '{$label}')]")->pause(700);
    }

    private function dataEntry(string $label = 'Kundenliste'): Menu
    {
        return Menu::create([
            'label' => $label,
            'route' => DataMenu::ROUTE,
            'route_parameters' => ['table' => 'kunden'],
            'permission_name' => DataPermissions::name('kunden', 'view'),
            'view_config' => ['columns' => [$this->fieldId($this->kunden, 'firma'), $this->fieldId($this->kunden, 'ort')]],
            'sort_order' => 700,
            'is_active' => true,
        ]);
    }

    /** MEN-01 */
    public function test_data_table_entry_under_own_group_shows_chosen_columns(): void
    {
        $group = Menu::create(['label' => 'Vertrieb', 'sort_order' => 600, 'is_active' => true]);

        $this->browse(function (Browser $browser) use ($group): void {
            $this->login($browser, self::ADMIN)->visit('/admin/menus/create')->waitFor('input[id="form.label.de"]', 15);
            $this->pickOption($browser, 'form.parent_id', 'Vertrieb');
            $this->chooseType($browser, 'Datentabelle');
            $this->pickOption($browser, 'form.data_table', 'Kunden');

            // Die Bezeichnung wird aus der Tabelle vorgeschlagen, die Spalten sind vorbelegt.
            $browser->waitUsing(15, 100, fn (): bool => $browser->value('input[id="form.label.de"]') === 'Kunden')
                ->waitFor($this->column($this->fieldId($this->kunden, 'land')), 15);
            $this->assertTrue($browser->element($this->column($this->fieldId($this->kunden, 'land')))->isSelected());
            $browser->click($this->column($this->fieldId($this->kunden, 'land')))->pause(300);

            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitUsing(15, 100, static fn (): bool => Menu::query()->where('route', DataMenu::ROUTE)->exists());

            $entry = Menu::query()->where('route', DataMenu::ROUTE)->firstOrFail();
            $this->assertSame($group->id, $entry->parent_id);
            $this->assertSame(DataPermissions::name('kunden', 'view'), $entry->permission_name);

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Vertrieb')
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Kunden']")
                ->waitFor('th.fi-ta-header-cell-firma', 15)
                ->assertPresent('th.fi-ta-header-cell-ort')
                ->assertMissing('th.fi-ta-header-cell-land');
        });
    }

    /** MEN-02 */
    public function test_required_fields_are_a_hint_and_not_selectable(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/menus/create')->waitFor('input[id="form.label.de"]', 15);
            $this->chooseType($browser, 'Datentabelle');
            $this->pickOption($browser, 'form.data_table', 'Kunden');

            $browser->waitForText('Immer angezeigt (Pflicht): Firma', 15)
                ->assertPresent($this->column($this->fieldId($this->kunden, 'ort')))
                ->assertMissing($this->column($this->fieldId($this->kunden, 'firma')));
        });
    }

    /** MEN-03 */
    public function test_changing_the_table_presets_its_columns_and_retargets_the_entry(): void
    {
        $entry = $this->dataEntry();

        $this->browse(function (Browser $browser) use ($entry): void {
            $this->login($browser, self::ADMIN)->visit("/admin/menus/{$entry->id}/edit")
                ->waitFor($this->column($this->fieldId($this->kunden, 'ort')), 15);
            $this->pickOption($browser, 'form.data_table', 'Artikel');

            $browser->waitFor($this->column($this->fieldId($this->artikel, 'farbe')), 15)
                ->assertMissing($this->column($this->fieldId($this->kunden, 'ort')));
            $this->assertTrue($browser->element($this->column($this->fieldId($this->artikel, 'farbe')))->isSelected());

            $browser->press('Speichern')->waitForText('Gespeichert', 15);

            $fresh = $entry->fresh();
            $this->assertSame(['table' => 'artikel'], $fresh->route_parameters);
            $this->assertSame(DataPermissions::name('artikel', 'view'), $fresh->permission_name);

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Kundenliste']")
                ->waitForLocation('/admin/data/artikel', 15);
        });
    }

    /** MEN-04 */
    public function test_changing_the_type_removes_old_table_settings(): void
    {
        $entry = $this->dataEntry();

        $this->browse(function (Browser $browser) use ($entry): void {
            $this->login($browser, self::ADMIN)->visit("/admin/menus/{$entry->id}/edit")->waitFor('input[id="form.label.de"]', 15);

            $this->chooseType($browser, 'Externe URL');
            $browser->waitFor('input[id="form.url"]', 15)->type('input[id="form.url"]', 'https://example.com/hilfe')
                ->press('Speichern')->waitForText('Gespeichert', 15);

            $fresh = $entry->fresh();
            $this->assertNull($fresh->route);
            $this->assertNull($fresh->route_parameters);
            $this->assertNull($fresh->view_config);
            $this->assertSame('https://example.com/hilfe', $fresh->url);
            $this->assertNull($fresh->permission_name);

            $this->chooseType($browser, 'Gruppe');
            $browser->press('Speichern')->waitUsing(15, 100, static fn (): bool => $entry->fresh()->url === null);

            $this->assertNull($entry->fresh()->route);
        });
    }

    /** MEN-05 */
    public function test_data_entry_follows_the_read_right_of_its_table(): void
    {
        $this->dataEntry();
        $reader = $this->activeUser('leser@example.com');

        $this->browse(function (Browser $browser) use ($reader): void {
            $this->login($browser, 'leser@example.com')->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Kundenliste');

            $reader->givePermissionTo(DataPermissions::name('kunden', 'view'));
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Kundenliste');
        });
    }

    /** MEN-06 */
    public function test_deleting_a_group_warns_and_removes_its_children(): void
    {
        $group = Menu::create(['label' => 'Alte Gruppe', 'sort_order' => 600, 'is_active' => true]);
        $child = Menu::create(['parent_id' => $group->id, 'label' => 'Altes Kind', 'url' => 'https://example.com', 'sort_order' => 1, 'is_active' => true]);

        $this->browse(function (Browser $browser): void {
            // Die Gruppe steht wegen ihrer Sortierung nicht auf der ersten Seite.
            $this->login($browser, self::ADMIN)->visit('/admin/menus')->waitFor('.fi-ta-search-field input', 15)
                ->type('.fi-ta-search-field input', 'Alte Gruppe')
                ->waitUsing(15, 100, fn (): bool => count($browser->elements('tr.fi-ta-row')) === 1);
            $browser->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'Alte Gruppe')][not(contains(., 'Altes Kind'))]//button[contains(., 'Löschen') or @aria-label='Löschen']")
                ->waitForText('Untergeordnete Einträge werden ebenfalls gelöscht', 15)
                ->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')
                ->waitUsing(15, 100, static fn (): bool => Menu::query()->where('label->de', 'Alte Gruppe')->doesntExist());
            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Altes Kind');
        });

        $this->assertNull(Menu::query()->find($group->id));
        $this->assertNull(Menu::query()->find($child->id));
    }

    /** MEN-07 */
    public function test_inactive_entry_leaves_the_sidebar_but_target_stays_reachable(): void
    {
        $entry = $this->dataEntry();

        $this->browse(function (Browser $browser) use ($entry): void {
            $this->login($browser, self::ADMIN)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Kundenliste')
                ->visit("/admin/menus/{$entry->id}/edit")
                ->waitFor('[id="form.is_active"]', 15)
                ->click('[id="form.is_active"]')
                ->press('Speichern')
                ->waitForText('Gespeichert', 15);

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Kundenliste');
            $browser->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
        });
    }

    /** MEN-08 */
    public function test_menu_list_shows_paths_and_filters_by_parent_and_status(): void
    {
        $group = Menu::create(['label' => 'Obergruppe', 'sort_order' => 600, 'is_active' => true]);
        Menu::create(['parent_id' => $group->id, 'label' => 'Unterpunkt', 'url' => 'https://example.com', 'sort_order' => 1, 'is_active' => false]);

        $this->browse(function (Browser $browser) use ($group): void {
            $this->login($browser, self::ADMIN)->visit('/admin/menus')->waitForText('Unterpunkt', 15)->assertSee('Obergruppe ›');

            $labels = fn (): array => array_map(
                static fn ($cell): string => trim($cell->getText()),
                $browser->driver->findElements(WebDriverBy::cssSelector('td.fi-ta-cell-label')),
            );

            $this->applyFilters($browser, static function (Browser $b) use ($group): void {
                $b->select('select[id="tableFiltersForm.parent_id.value"]', (string) $group->id);
            })->waitUsing(15, 100, fn (): bool => count($labels()) === 1);
            $this->assertCount(1, $labels());
            $this->assertStringContainsString('Unterpunkt', $labels()[0]);
            $this->resetFilters($browser)->waitUsing(15, 100, fn (): bool => count($labels()) > 1);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->select('select[id="tableFiltersForm.parent_id.value"]', 'root');
            })->waitUntilMissingText('Unterpunkt', 15);
            $this->assertContains('Obergruppe', $labels());
            $this->resetFilters($browser)->waitForText('Unterpunkt', 15);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->select('select[id="tableFiltersForm.is_active.value"]', '0');
            })->waitUsing(15, 100, fn (): bool => count($labels()) === 1);
            $this->assertCount(1, $labels());
            $this->assertStringContainsString('Unterpunkt', $labels()[0]);
        });
    }

    /** MEN-09 */
    public function test_valid_icon_appears_in_the_sidebar(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/menus/create')->waitFor('input[id="form.label.de"]', 15)
                ->type('input[id="form.label.de"]', 'Mit Icon')
                ->type('input[id="form.icon"]', 'heroicon-o-star');
            $this->chooseType($browser, 'Externe URL');
            $browser->waitFor('input[id="form.url"]', 15)->type('input[id="form.url"]', 'https://example.com/stern');
            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitUsing(15, 100, static fn (): bool => Menu::query()->where('label->de', 'Mit Icon')->exists());

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Mit Icon');
            $icons = $browser->driver->findElements(WebDriverBy::xpath(
                "//a[contains(@class, 'fi-sidebar-item-btn')][contains(., 'Mit Icon')]//*[name()='svg']",
            ));
            $this->assertCount(1, $icons);
        });
    }

    /** MEN-10 */
    public function test_icon_is_chosen_in_the_dialog_with_tabs_and_search(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/menus/create')->waitFor('input[id="form.icon"]', 15);

            $browser->click('button[aria-label="Icon auswählen"]')->waitFor('.saft-icon-picker', 15)
                ->waitFor('.saft-icon-picker button[data-icon="heroicon-o-users"]', 15);
            $visible = static fn (Browser $b, string $icon): bool => (bool) $b->script(
                "const e = document.querySelector('.saft-icon-picker button[data-icon=\"{$icon}\"]'); return !!e && e.offsetParent !== null;",
            )[0];
            $this->assertTrue($visible($browser, 'heroicon-o-users'), 'Outline ist der erste Reiter.');
            $this->assertFalse($visible($browser, 'heroicon-s-users'));

            // Suche filtert sofort; die Trefferzahl steht am Reiter.
            $browser->type('.saft-icon-picker input[type="search"]', 'star')
                ->waitUsing(10, 100, fn (): bool => ! $visible($browser, 'heroicon-o-users'));
            $this->assertTrue($visible($browser, 'heroicon-o-star'));
            $expected = count(array_filter(
                collect(IconCatalog::groups())->firstWhere('key', 'solid')['icons'],
                static fn (string $icon): bool => str_contains(substr($icon, strlen('heroicon-s-')), 'star'),
            ));
            $badge = trim((string) $browser->script(
                "return document.querySelector('.saft-icon-picker [data-group=\"solid\"] .fi-badge-label').textContent;",
            )[0]);
            $this->assertSame((string) $expected, $badge);

            // Reiter Solid behält den Suchbegriff; Klick übernimmt das Icon und schließt den Dialog.
            $browser->click('.saft-icon-picker [data-group="solid"]')
                ->waitUsing(10, 100, fn (): bool => $visible($browser, 'heroicon-s-star'));
            $this->assertFalse($visible($browser, 'heroicon-s-users'));
            $browser->click('.saft-icon-picker button[data-icon="heroicon-s-star"]')
                ->waitUntilMissing('.saft-icon-picker', 15)
                ->waitUsing(10, 100, static fn (): bool => true);
            $this->assertSame('heroicon-s-star', $browser->inputValue('input[id="form.icon"]'));
        });
    }
}
