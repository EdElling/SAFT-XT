<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Role;
use App\Models\User;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\Concerns\ConfiguresAuth;
use Tests\DuskTestCase;

/** Benutzerverwaltung (Bereich BEN). */
class UsersTest extends DuskTestCase
{
    use ConfiguresAuth;

    private const string ADMIN = 'admin@example.com';

    private const string OTHER = 'anna@example.com';

    private const string NEW_PASSWORD = 'Neu-Geheim-2026!x';

    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->superAdmin();
        $this->other = $this->standardUser(self::OTHER);
    }

    private function loginFails(Browser $browser, string $email, string $password = 'password'): void
    {
        $browser->visit('/admin/login');
        $this->submitLogin($browser, $email, $password)->waitForText(self::TEXT_LOGIN_FAILED, 15)->assertMissing(self::SELECTOR_SIDEBAR);
    }

    private function rowActions(Browser $browser, string $rowText): array
    {
        return array_map(
            // Symbol-Knöpfe (B-113): Bezeichnung im aria-label.
            static fn ($action): string => trim($action->getText()) !== '' ? trim($action->getText()) : trim((string) $action->getAttribute('aria-label')),
            $browser->driver->findElements(WebDriverBy::xpath(
                "//tr[contains(@class, 'fi-ta-row')][contains(., '{$rowText}')]//*[contains(@class, 'fi-ta-actions')]//*[self::button or self::a]",
            )),
        );
    }

    private function selectRow(Browser $browser, User $user): Browser
    {
        return $browser->click("input[type=\"checkbox\"][value=\"{$user->id}\"]")->pause(300);
    }

    private function bulk(Browser $browser, string $label): Browser
    {
        return $browser->clickAtXPath("//button[contains(., 'Mehrfachaktionen')]")
            ->waitForText($label, 15)
            ->clickAtXPath("//*[contains(@class, 'fi-dropdown-list')]//button[contains(., '{$label}')]")
            ->waitForText('Abbrechen', 15)
            ->pause(300);
    }

    private function showOnlyTrashed(Browser $browser): Browser
    {
        return $this->applyFilters($browser, static function (Browser $b): void {
            $b->waitFor('select[id="tableFiltersForm.trashed.value"]', 15)->select('select[id="tableFiltersForm.trashed.value"]', '0');
        });
    }

    /** BEN-01 */
    public function test_own_account_cannot_be_deactivated_or_deleted(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitForText(self::ADMIN, 15);

            $this->assertNotContains('Löschen', $this->rowActions($browser, self::ADMIN));
            $this->assertContains('Löschen', $this->rowActions($browser, self::OTHER));

            $browser->visit("/admin/users/{$this->admin->id}/edit")->waitFor('[id="form.is_active"]', 15)->assertDontSee('Löschen');
            $this->assertSame('true', $browser->attribute('[id="form.is_active"]', 'disabled'));
        });
    }

    /** BEN-02 */
    public function test_empty_password_keeps_it_and_new_password_replaces_it(): void
    {
        $this->browse(function (Browser $admin, Browser $affected): void {
            $this->login($admin, self::ADMIN)->visit("/admin/users/{$this->other->id}/edit")->waitFor('input[id="form.name"]', 15)
                ->clear('input[id="form.name"]')->type('input[id="form.name"]', 'Anna Neu')
                ->press('Speichern')->waitForText('Gespeichert', 15);

            $this->login($affected, self::OTHER)->assertPathIs('/admin');
            $this->logout($affected);

            $admin->type('input[id="form.password"]', self::NEW_PASSWORD)
                ->type('input[id="form.password_confirmation"]', self::NEW_PASSWORD)
                ->press('Speichern')->pause(800)->waitForText('Gespeichert', 15);

            $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $this->other->fresh()->password));
            $this->loginFails($affected, self::OTHER);
            $this->login($affected, self::OTHER, self::NEW_PASSWORD)->assertPathIs('/admin');
        });
    }

    /** BEN-03 */
    public function test_deleted_user_cannot_login_until_restored(): void
    {
        $this->browse(function (Browser $admin, Browser $affected): void {
            $this->login($admin, self::ADMIN)->visit('/admin/users')->waitForText(self::OTHER, 15);
            $this->rowAction($admin, self::OTHER, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($admin, 'Löschen')->waitUntilMissingText(self::OTHER, 15);

            $this->loginFails($affected, self::OTHER);

            $this->showOnlyTrashed($admin)->waitForText(self::OTHER, 15)->assertDontSee(self::ADMIN);
            $this->rowAction($admin, self::OTHER, 'Wiederherstellen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($admin, 'Wiederherstellen')->waitForText('wiederhergestellt', 15);

            $this->login($affected, self::OTHER)->assertPathIs('/admin');
        });
    }

    /** BEN-04 */
    public function test_email_of_a_deleted_user_gives_a_field_error(): void
    {
        $this->other->delete();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users/create')->waitFor('input[id="form.name"]', 15)
                ->type('input[id="form.name"]', 'Neu')
                ->type('input[id="form.email"]', strtoupper(self::OTHER))
                ->type('input[id="form.password"]', self::NEW_PASSWORD)
                ->type('input[id="form.password_confirmation"]', self::NEW_PASSWORD);
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('bereits vergeben', 15)->assertPathIs('/admin/users/create');
        });

        $this->assertSame(0, User::query()->where('name', 'Neu')->count());
    }

    /** BEN-05 */
    public function test_bulk_delete_skips_the_own_account(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitForText(self::OTHER, 15);
            $this->selectRow($browser, $this->admin);
            $this->selectRow($browser, $this->other);

            $this->bulk($browser, 'Ausgewählte löschen');
            $this->clickVisibleButton($browser, 'Ausgewählte löschen')
                ->waitUntilMissingText(self::OTHER, 15)
                ->waitFor('.fi-no-notification', 15)
                ->assertSee(self::ADMIN)
                ->assertSeeIn('.fi-no-notification', '1');

            $this->assertTrue($this->other->fresh()->trashed());
            $this->assertFalse($this->admin->fresh()->trashed());
        });
    }

    /** BEN-06 */
    public function test_force_delete_removes_user_but_keeps_the_log_readable(): void
    {
        activity()->causedBy($this->other)->performedOn($this->admin)->log('Vorher geändert');
        $this->other->delete();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitFor('.fi-ta-table', 15);
            $this->showOnlyTrashed($browser)->waitForText(self::OTHER, 15);
            $this->selectRow($browser, $this->other);

            $this->bulk($browser, 'Ausgewählte endgültig löschen');
            $this->clickVisibleButton($browser, 'Löschen')->waitUntilMissingText(self::OTHER, 15);

            $this->assertNull(User::withTrashed()->find($this->other->id));

            $browser->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)
                ->assertSee("Gelöschter Benutzer #{$this->other->id}")
                ->assertDontSee('Unerwarteter Fehler');
            $browser->clickAtXPath("(//tr[contains(@class, 'fi-ta-row')]//*[self::button or self::a][contains(., 'Anzeigen') or @aria-label='Anzeigen'])[1]")
                ->waitForText('Beschreibung', 15)
                ->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** BEN-07 */
    public function test_user_list_filters_work_alone_and_combined(): void
    {
        $this->activeUser('ohnerolle@example.com');
        $this->standardUser('inaktiv@example.com')->update(['is_active' => false]);
        $this->standardUser('sso@example.com')->social_accounts()->create([
            'provider' => 'entra',
            'provider_user_id' => 'abc',
            'identity_provider_id' => $this->entraProvider()->id,
        ]);
        $this->standardUser('geloescht@example.com')->delete();

        $this->browse(function (Browser $browser): void {
            $emails = fn (): array => array_values(array_filter(
                ['admin', 'anna', 'ohnerolle', 'inaktiv', 'sso', 'geloescht'],
                static fn (string $name): bool => str_contains($browser->text('.fi-ta-table'), $name.'@example.com'),
            ));
            $reset = fn () => $this->resetFilters($browser);

            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitForText(self::OTHER, 15);
            $this->assertSame(['admin', 'anna', 'ohnerolle', 'inaktiv', 'sso'], $emails());

            $this->applyFilters($browser, fn (Browser $b) => $b->select('select[id="tableFiltersForm.is_active.value"]', '0'));
            $this->assertSame(['inaktiv'], $emails());
            $reset();

            $this->applyFilters($browser, fn (Browser $b) => $b->click('button[id="tableFiltersForm.without_roles.isActive"]'));
            $this->assertSame(['ohnerolle'], $emails());
            $reset();

            $this->applyFilters($browser, fn (Browser $b) => $b->select('select[id="tableFiltersForm.sso_linked.value"]', '1'));
            $this->assertSame(['sso'], $emails());
            $reset();

            $this->applyFilters($browser, fn (Browser $b) => $this->pickOption($b, 'tableFiltersForm.roles.values', User::ROLE_SUPER_ADMIN));
            $this->assertSame(['admin'], $emails());
            $reset();

            $this->applyFilters($browser, function (Browser $b): void {
                $this->pickOption($b, 'tableFiltersForm.roles.values', User::ROLE_DEFAULT);
                $b->select('select[id="tableFiltersForm.is_active.value"]', '1');
            });
            $this->assertSame(['anna', 'sso'], $emails());
            $reset();

            $this->applyFilters($browser, fn (Browser $b) => $b->select('select[id="tableFiltersForm.trashed.value"]', '0'));
            $this->assertSame(['geloescht'], $emails());
            $reset();

            $this->assertSame(['admin', 'anna', 'ohnerolle', 'inaktiv', 'sso'], $emails());
        });
    }

    /** BEN-08 */
    public function test_manager_cannot_touch_super_admin(): void
    {
        $manager = Role::create(['name' => 'verwalter', 'guard_name' => 'web']);
        $manager->givePermissionTo(['users.view', 'users.create', 'users.update', 'users.delete']);
        $this->activeUser('verwalter@example.com')->assignRole($manager);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'verwalter@example.com')->visit('/admin/users')->waitForText(self::ADMIN, 15);

            $this->assertSame([], $this->rowActions($browser, self::ADMIN));
            $this->assertContains('Bearbeiten', $this->rowActions($browser, self::OTHER));

            $browser->visit("/admin/users/{$this->admin->id}/edit")->waitForText('Keine Berechtigung', 15);

            $browser->visit("/admin/users/{$this->other->id}/edit")->waitFor('button[id="form.roles"]', 15)
                ->click('button[id="form.roles"]');
            $list = '.fi-select-input-ctn:has(button[id="form.roles"]) ul[role="listbox"]';
            $browser->waitUsing(15, 100, fn (): bool => filled($browser->text($list)));
            $options = $browser->text($list);
            // Vergebene Rollen stehen nicht mehr in der Auswahl, daher „verwalter“ statt „benutzer“.
            $this->assertStringContainsString('verwalter', $options);
            $this->assertStringNotContainsString(User::ROLE_SUPER_ADMIN, $options);
        });
    }

    /** BEN-09 */
    public function test_last_login_is_current_with_exact_time_in_tooltip(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitForText(self::ADMIN, 15);
            $cell = '//tr[contains(@class, "fi-ta-row")][contains(., "'.self::ADMIN.'")]//td[contains(@class, "last-login") or contains(@class, "last_login")]';
            $text = $browser->driver->findElement(WebDriverBy::xpath($cell))->getText();

            $this->assertMatchesRegularExpression('/vor \d+ (Sekunde|Minute)/', $text);

            $browser->driver->action()->moveToElement($browser->driver->findElement(WebDriverBy::xpath($cell.'//*[contains(., "vor")][last()]')))->perform();
            $browser->waitForText(now()->format('d.m.Y'), 15);
        });
    }

    /** BEN-10 */
    public function test_history_shows_name_and_role_changes(): void
    {
        Role::create(['name' => 'leitung', 'guard_name' => 'web']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit("/admin/users/{$this->other->id}/edit")->waitFor('input[id="form.name"]', 15)
                ->clear('input[id="form.name"]')->type('input[id="form.name"]', 'Anna Neu');
            $this->pickOption($browser, 'form.roles', 'leitung');
            $browser->press('Speichern')->waitForText('Gespeichert', 15);

            $oldName = $this->other->name;
            $browser->clickAtXPath("//button[contains(., 'Änderungshistorie')]")
                ->waitForText('Zeitpunkt', 15);

            // Name und Rollen sind getrennte Einträge derselben Sekunde, ihre Reihenfolge ist offen.
            $entry = "(//tr[contains(@class, 'fi-ta-row')][contains(., 'geändert')]//*[self::button or self::a][contains(., 'Anzeigen') or @aria-label='Anzeigen'])";
            $entries = '';

            foreach ([1, 2] as $number) {
                $browser->clickAtXPath("{$entry}[{$number}]")->waitForText('Nachher', 15);
                $entries .= $browser->script(<<<'JS'
                    return [...document.querySelectorAll('.fi-modal-window')]
                        .filter(modal => modal.offsetParent !== null && modal.innerText.includes('Nachher'))
                        .map(modal => modal.innerText)
                        .join(' ');
                JS)[0];
                $this->clickVisibleButton($browser, 'Schliessen')->waitUntilMissingText('Nachher', 15);
            }

            foreach ([$oldName, 'Anna Neu', 'roles', 'leitung'] as $expected) {
                $this->assertStringContainsString($expected, $entries);
            }
        });
    }

    /** BEN-11 */
    public function test_force_delete_as_row_action_with_explanation(): void
    {
        $this->other->delete();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitFor('.fi-ta-table', 15);
            $this->assertNotContains('Endgültig löschen', $this->rowActions($browser, self::ADMIN));

            $this->showOnlyTrashed($browser)->waitForText(self::OTHER, 15);
            $this->assertContains('Endgültig löschen', $this->rowActions($browser, self::OTHER));

            $this->rowAction($browser, self::OTHER, 'Endgültig löschen')->waitForText('neues Konto entstehen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')->waitUntilMissingText(self::OTHER, 15);

            $this->assertNull(User::withTrashed()->find($this->other->id));
        });
    }
}
