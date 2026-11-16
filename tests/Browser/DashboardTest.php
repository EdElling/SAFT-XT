<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\Models\DynamicRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ConfiguresAuth;
use Tests\DuskTestCase;

/** Dashboard (Bereich DSH). */
class DashboardTest extends DuskTestCase
{
    use ConfiguresAuth;

    private const string ADMIN = 'admin@example.com';

    /** Liest die Zahl eines Kennzahl-Felds, z. B. „Aktiv“. */
    private function stat(Browser $browser, string $label): string
    {
        return trim($browser->driver->findElement(WebDriverBy::xpath(
            "//*[contains(@class, 'fi-wi-stats-overview-stat')][.//*[contains(@class, 'fi-wi-stats-overview-stat-label')][normalize-space(.)='{$label}']]//*[contains(@class, 'fi-wi-stats-overview-stat-value')]",
        ))->getText());
    }

    private function waitForStat(Browser $browser, string $label): Browser
    {
        $this->scrollThroughPage($browser);

        return $browser->waitForText($label, 15);
    }

    /** DSH-01 */
    public function test_all_eight_widgets_load_for_super_admin(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->scrollThroughPage($browser);

            foreach ([
                'Meine letzte Anmeldung', 'Meine Anmeldungen', 'Meine letzten Änderungen', 'Änderungen an meinem Konto',
                'Letzte Änderungen', 'Identity-Provider-Status', 'Anmeldeaktivität', 'Benutzerübersicht',
            ] as $heading) {
                $browser->waitForText($heading, 15);
            }

            $browser->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** DSH-02 */
    public function test_previous_login_shows_method_and_ip_of_the_earlier_login(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $first, Browser $second): void {
            $this->login($first, self::ADMIN);
            $this->waitForStat($first, 'Vorherige Anmeldung')->assertSee('Dies ist Ihre erste Anmeldung');

            $this->login($second, self::ADMIN);
            $this->waitForStat($second, 'Vorherige Anmeldung')
                ->waitForText('IP 127.0.0.1', 15)
                ->assertSee('Passwort')
                ->assertDontSee('Dies ist Ihre erste Anmeldung');
        });
    }

    /** DSH-03 */
    public function test_failed_attempts_show_until_the_next_login(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitFor('.fi-ta-table', 15);
            $this->logout($browser);

            $this->submitLogin($browser, self::ADMIN, 'falsch')->waitForText(self::TEXT_LOGIN_FAILED, 15);

            $this->login($browser, self::ADMIN);
            $this->waitForStat($browser, 'Fehlgeschlagene Versuche seither')->waitForText('falsches Passwort', 15);
            $this->assertSame('1', $this->stat($browser, 'Fehlgeschlagene Versuche seither'));

            $browser->visit('/admin/users')->waitFor('.fi-ta-table', 15);
            $this->logout($browser);
            $this->login($browser, self::ADMIN);
            $this->waitForStat($browser, 'Fehlgeschlagene Versuche seither');
            $this->assertSame('0', $this->stat($browser, 'Fehlgeschlagene Versuche seither'));
        });
    }

    /** DSH-04 */
    public function test_change_entry_opens_the_record_selected(): void
    {
        $admin = $this->superAdmin();
        $table = app(DynamicSchemaService::class)->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string'],
        ]);
        app(DataMenu::class)->ensure($table);
        $record = DynamicRecord::forTable($table)->create(['firma' => 'ACME GmbH']);
        DynamicRecord::forTable($table)->create(['firma' => 'Beta AG']);
        activity()->causedBy($admin)->performedOn($record)->event('updated')->log('updated');

        $this->browse(function (Browser $browser) use ($record): void {
            $this->login($browser, self::ADMIN);
            $this->scrollThroughPage($browser);
            $browser->waitForText('Meine letzten Änderungen', 15)->waitForText("Daten: Kunden #{$record->id}", 15)
                ->pause(200);
            $this->clickCentered($browser, "//tr[contains(@class, 'fi-ta-row')][contains(., 'Daten: Kunden #{$record->id}')]//td[1]")
                ->waitFor('tr.saft-row-selected', 15)
                ->assertSeeIn('tr.saft-row-selected', 'ACME GmbH')
                ->assertPathIs('/admin/data/kunden');
        });
    }

    /** DSH-05 */
    public function test_user_overview_numbers_link_to_matching_filtered_lists(): void
    {
        $this->superAdmin();
        $this->standardUser('anna@example.com');
        $this->standardUser('inaktiv1@example.com')->update(['is_active' => false]);
        $this->activeUser('ohnerolle@example.com');
        $this->standardUser('sso@example.com')->social_accounts()->create([
            'provider' => 'entra', 'provider_user_id' => 'x', 'identity_provider_id' => $this->entraProvider()->id,
        ]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);

            foreach ([
                'Aktiv' => ['count' => 4, 'see' => 'anna@example.com', 'not' => 'inaktiv1@example.com'],
                'Inaktiv' => ['count' => 1, 'see' => 'inaktiv1@example.com', 'not' => 'anna@example.com'],
                'Ohne Rolle' => ['count' => 1, 'see' => 'ohnerolle@example.com', 'not' => 'anna@example.com'],
                'Mit SSO-Verknüpfung' => ['count' => 1, 'see' => 'sso@example.com', 'not' => 'anna@example.com'],
            ] as $label => $expected) {
                $browser->visit('/admin');
                $this->waitForStat($browser, 'Benutzerübersicht')->waitForText($label, 15);
                $this->assertSame((string) $expected['count'], $this->stat($browser, $label), $label);

                $this->clickCentered($browser, "//a[contains(@class, 'fi-wi-stats-overview-stat')][.//*[normalize-space(.)='{$label}']]")
                    ->waitForLocation('/admin/users', 15)
                    ->waitForText($expected['see'], 15)
                    ->assertDontSee($expected['not']);

                $this->assertCount($expected['count'], $browser->elements('tr.fi-ta-row'), $label);
            }
        });
    }

    /** DSH-06 */
    public function test_identity_provider_row_opens_its_dialog(): void
    {
        $this->superAdmin();
        $provider = $this->entraProvider();

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN);
            $this->scrollThroughPage($browser);
            $browser->waitForText('Firmen-Login', 15);
            $this->clickCentered($browser, "//tr[contains(@class, 'fi-ta-row')][contains(., 'Firmen-Login')]//td[1]")
                ->waitForLocation("/admin/identity-providers/{$provider->slug}/edit", 15)
                ->assertPathIs("/admin/identity-providers/{$provider->slug}/edit");
        });
    }

    /** DSH-07 */
    public function test_active_users_counts_each_logged_in_user(): void
    {
        $this->superAdmin();
        $this->standardUser();

        $this->browse(function (Browser $admin, Browser $other): void {
            $this->login($other, 'benutzer@example.com');
            $this->login($admin, self::ADMIN);
            $this->waitForStat($admin, 'Angemeldete Benutzer');
            $this->assertSame('2', $this->stat($admin, 'Angemeldete Benutzer'));
        });
    }

    /** DSH-08 */
    public function test_widget_follows_the_right_given_by_a_role(): void
    {
        $role = Role::create(['name' => 'nur_widget', 'guard_name' => 'web']);
        $this->activeUser('leser@example.com')->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->browse(function (Browser $browser) use ($role): void {
            $this->login($browser, 'leser@example.com')->pause(800)->assertDontSee('Meine letzte Anmeldung');

            $role->givePermissionTo('widgets.last_login');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $browser->visit('/admin')->waitForText('Meine letzte Anmeldung', 15);

            $role->revokePermissionTo('widgets.last_login');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->pause(800)->assertDontSee('Meine letzte Anmeldung');
        });
    }

    /** DSH-11 */
    public function test_cards_are_rearranged_hidden_and_kept_after_reload(): void
    {
        $admin = $this->superAdmin();
        $layout = fn (): array => (array) $admin->fresh()->dashboard_layout;

        $this->browse(function (Browser $browser) use ($layout): void {
            $this->login($browser, self::ADMIN)->resize(1400, 1000);
            $this->clickVisibleButton($browser, 'Dashboard anpassen')->waitFor('[data-dashboard-editor]', 15);

            // Halbbreite Karte in die andere Spalte, oben ablegen.
            $this->dragInSteps($browser, '[data-dashboard-card="MyLoginsWidget"] [data-dashboard-handle]', '[data-dashboard-column="right"] [data-dashboard-card="LastLoginWidget"]');
            $browser->waitUsing(15, 200, fn (): bool => in_array('MyLoginsWidget', $layout()['right'] ?? [], true));

            // In den breiten Bereich darf sie nicht.
            $this->dragInSteps($browser, '[data-dashboard-card="MyLoginsWidget"] [data-dashboard-handle]', '[data-dashboard-column="full"] [data-dashboard-card="RecentChangesWidget"]');
            $browser->pause(800);
            $this->assertNotContains('MyLoginsWidget', $layout()['full'] ?? []);

            $browser->script('document.querySelector(\'[data-hide-card="MyChangesWidget"]\').scrollIntoView({block: "center"});');
            $browser->click('[data-hide-card="MyChangesWidget"]')
                ->waitFor('[data-show-card="MyChangesWidget"]', 10)
                ->assertMissing('[data-dashboard-card="MyChangesWidget"]');
            $browser->script('window.scrollTo(0, 0);');
            $this->clickVisibleButton($browser->pause(300), 'Fertig')->waitUntilMissing('[data-dashboard-editor]', 10);

            // Nach dem Neuladen gleiche Anordnung, ausgeblendete Karte fehlt.
            $browser->refresh()->waitFor('[data-dashboard-column="right"]', 15)
                ->assertMissing('[data-dashboard-card="MyChangesWidget"]')
                ->assertPresent('[data-dashboard-column="right"] [data-dashboard-card="MyLoginsWidget"]')
                ->assertDontSee('Unerwarteter Fehler');
        });

        $this->assertSame(['MyChangesWidget'], $layout()['hidden']);
    }

    /** DSH-09 */
    public function test_dashboard_without_widgets_has_no_errors(): void
    {
        $this->activeUser('leer@example.com');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'leer@example.com')->pause(800)
                ->assertSee('Dashboard')
                ->assertDontSee('Unerwarteter Fehler')
                ->assertDontSee('Keine Berechtigung')
                ->assertMissing('.fi-wi-stats-overview');
        });

        $this->assertSame(0, User::query()->where('email', 'leer@example.com')->firstOrFail()->getAllPermissions()->count());
    }
}
