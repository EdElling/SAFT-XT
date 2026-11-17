<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\Models\Menu;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\CreatesOrderData;
use Tests\DuskTestCase;

/** Navigation, Fehlerseite und Kaskade (Bereiche NAV, ERR, KAS). */
class NavigationTest extends DuskTestCase
{
    use CreatesOrderData;

    private const string ACTIVE_ITEM = '.fi-sidebar-item.fi-active';

    /** NAV-04 */
    public function test_only_the_opened_entry_is_highlighted_for_two_entries_of_one_table(): void
    {
        $this->superAdmin();
        $this->createOrderData();
        $first = Menu::query()->where('route_parameters->table', 'kunden')->firstOrFail();
        Menu::create([
            'parent_id' => $first->parent_id,
            'label' => 'Kunden kurz',
            'route' => DataMenu::ROUTE,
            'route_parameters' => ['table' => 'kunden'],
            'view_config' => ['columns' => [$this->fieldId($this->kunden, 'firma')]],
            'sort_order' => 999,
            'is_active' => true,
        ]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com');

            foreach (['Kunden kurz', 'Kunden'] as $label) {
                $browser->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='{$label}']")
                    ->waitForText('ACME GmbH', 15)
                    ->waitUsing(15, 100, fn (): bool => $this->activeItemLabels($browser) === [$label]);

                $this->assertSame([$label], $this->activeItemLabels($browser));
            }
        });
    }

    /** @return list<string> */
    private function activeItemLabels(Browser $browser): array
    {
        return array_map(
            static fn ($element): string => trim((string) $element->getText()),
            $browser->elements(self::ACTIVE_ITEM),
        );
    }

    /** NAV-05 */
    public function test_menu_entry_stays_highlighted_after_selecting_a_row(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com')->clickLink('Kunden');

            $this->clickRow($browser, 'ACME GmbH')
                ->waitFor('tr.saft-row-selected', 15)
                ->assertSeeIn(self::ACTIVE_ITEM, 'Kunden');
            $this->assertCount(1, $browser->elements(self::ACTIVE_ITEM));
        });
    }

    /** NAV-06 */
    public function test_spa_navigation_with_back_and_forward_keeps_the_page_working(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com');

            // Rollen liegen im Untermenü „Benutzer und Zugriff“: erst aufklappen (öffnet Benutzer), dann Rollen.
            $browser->clickLink('Benutzer und Zugriff')->waitForLocation('/admin/users', 15)
                ->clickLink('Rollen')->waitForLocation('/admin/roles', 15)
                ->clickLink('Kunden')->waitForText('ACME GmbH', 15)
                ->back()->waitForLocation('/admin/roles', 15)->waitForText('super-admin', 15);

            $browser->driver->navigate()->forward();

            $browser->waitForText('ACME GmbH', 15);
            $this->clickRow($browser, 'ACME GmbH')
                ->waitFor('tr.saft-row-selected', 15)
                ->assertSeeIn('tr.saft-row-selected', 'ACME GmbH');
        });
    }

    /** NAV-07 */
    public function test_direct_access_without_permission_shows_german_error_page(): void
    {
        $this->standardUser();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'benutzer@example.com')
                ->visit('/admin/users')
                ->waitForText('Keine Berechtigung', 15)
                ->assertSeeLink('Zur Startseite')
                ->assertDontSee('admin@example.com');
        });
    }

    /** NAV-09 */
    public function test_invalid_icon_is_rejected_and_stored_ones_do_not_break_the_panel(): void
    {
        $this->superAdmin();
        Menu::create(['label' => 'Altes Icon', 'route' => 'filament.admin.resources.users.index', 'icon' => 'heroicon-o-gibtsnicht', 'is_active' => true, 'sort_order' => 950]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com')->assertSeeIn(self::SELECTOR_SIDEBAR, 'Altes Icon');

            $browser->visit('/admin/menus/create')
                ->waitFor('input[id="form.icon"]', 15)
                ->type('input[id="form.label.de"]', 'Neu')
                ->type('input[id="form.icon"]', 'heroicon-o-gibtsnicht')
                ->press('Erstellen')
                ->waitForText('Dieses Icon ist nicht bekannt', 15)
                ->assertPathIs('/admin/menus/create');
        });
    }

    /** ERR-01 */
    public function test_unknown_page_shows_german_error_with_working_home_link(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com')
                ->visit('/admin/gibtsnicht')
                ->assertSee('Nicht gefunden')
                ->clickLink('Zur Startseite')
                ->waitFor(self::SELECTOR_SIDEBAR, 15);
        });
    }

    /** KAS-01 */
    public function test_cascade_opens_three_levels_on_one_page(): void
    {
        $this->superAdmin();
        $this->createOrderData();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com')->clickLink('Kunden');

            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);
            $path = parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH);

            $this->clickRow($browser, 'B-100')
                ->waitForText('Schraube M8', 15)
                ->assertSee('ACME GmbH');

            $this->assertSame($path, parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH));
        });
    }

    /** NAV-02 */
    public function test_external_url_opens_in_a_new_tab(): void
    {
        $this->superAdmin();
        Menu::create(['label' => 'Extern', 'url' => config('app.url').'/robots.txt', 'sort_order' => 950, 'is_active' => true]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com');
            $start = $browser->driver->getCurrentURL();

            $browser->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Extern']")
                ->waitUsing(15, 100, fn (): bool => count($browser->driver->getWindowHandles()) === 2);

            $this->assertSame($start, $browser->driver->getCurrentURL());
        });
    }

    /** NAV-03 */
    public function test_third_menu_level_is_reachable(): void
    {
        $this->superAdmin();
        $group = Menu::create(['label' => 'Ebene 1', 'sort_order' => 950, 'is_active' => true]);
        $second = Menu::create(['parent_id' => $group->id, 'label' => 'Ebene 2', 'route' => 'filament.admin.resources.users.index', 'sort_order' => 1, 'is_active' => true]);
        Menu::create(['parent_id' => $second->id, 'label' => 'Ebene 3', 'route' => 'filament.admin.resources.roles.index', 'sort_order' => 1, 'is_active' => true]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com')
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Ebene 2']")
                ->waitForLocation('/admin/users', 15)
                ->waitForTextIn(self::SELECTOR_SIDEBAR, 'Ebene 3', 15)
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Ebene 3']")
                ->waitForLocation('/admin/roles', 15)
                ->assertPathIs('/admin/roles');
        });
    }

    /** NAV-08 */
    public function test_collapsed_menu_group_stays_collapsed_after_navigation(): void
    {
        $this->superAdmin();
        $this->createOrderData();
        $toggle = "//*[contains(@class, 'fi-sidebar-group')][.//*[contains(@class, 'fi-sidebar-group-label')][normalize-space(.)='Administration']]//button[contains(@class, 'fi-sidebar-group-collapse-btn')]";
        $item = "//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Benutzer und Zugriff']";
        $itemVisible = static fn (Browser $b): bool => $b->driver->findElement(WebDriverBy::xpath($item))->isDisplayed();

        $this->browse(function (Browser $browser) use ($toggle, $itemVisible): void {
            $this->login($browser, 'admin@example.com');
            $this->assertTrue($itemVisible($browser));

            $browser->clickAtXPath($toggle)->waitUsing(15, 100, fn (): bool => ! $itemVisible($browser));

            $browser->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Kunden']")
                ->waitForText('ACME GmbH', 15);
            $this->assertFalse($itemVisible($browser));

            $browser->clickAtXPath($toggle)->waitUsing(15, 100, fn (): bool => $itemVisible($browser));
            $this->assertTrue($itemVisible($browser));
        });
    }

    /** NAV-10 */
    public function test_sidebar_can_be_collapsed_and_stays_collapsed(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $sidebar = fn (): array => $browser->script("const s=document.querySelector('.fi-sidebar'); return [s.classList.contains('fi-sidebar-open'), Math.round(s.getBoundingClientRect().right), Math.round(document.querySelector('.fi-main').getBoundingClientRect().left)];")[0];

            $this->login($browser, 'admin@example.com')->visit('/admin/users')->waitFor('.fi-ta-table', 15)->pause(500);
            [$open, $right] = $sidebar();
            $this->assertTrue($open);
            $this->assertGreaterThan(0, $right);

            $this->clickVisible($browser, '.fi-topbar-close-collapse-sidebar-btn');
            $browser->pause(600);
            [$open, $right, $mainLeft] = $sidebar();
            $this->assertFalse($open);
            $this->assertLessThanOrEqual(0, $right, 'Die Seitenleiste ist aus dem Bild');
            $this->assertLessThan(100, $mainLeft, 'Der Inhalt nutzt den frei gewordenen Platz');

            // Der Zustand bleibt beim Neuladen und Seitenwechsel erhalten.
            $browser->visit('/admin/roles')->waitFor('.fi-ta-table', 15)->pause(500);
            $this->assertFalse($sidebar()[0]);

            $this->clickVisible($browser, '.fi-topbar-open-sidebar-btn');
            $browser->pause(600)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Benutzer');
            $this->assertTrue($sidebar()[0]);
        });
    }
}
