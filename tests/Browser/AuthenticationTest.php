<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Auth\AuthMode;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Concerns\ConfiguresAuth;
use Tests\DuskTestCase;

/** Anmeldung und Sitzung (Nummern siehe Testliste, Bereich ANM). */
class AuthenticationTest extends DuskTestCase
{
    use ConfiguresAuth;

    private const string ADMIN_EMAIL = 'admin@example.com';

    /** ANM-01 */
    public function test_login_is_throttled_after_five_failures_and_works_after_expiry(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            foreach (range(1, 5) as $attempt) {
                $browser->visit('/admin/login');
                $this->submitLogin($browser, 'admin@example.com', 'falsch-'.$attempt)
                    ->waitForText(self::TEXT_LOGIN_FAILED);
            }

            $browser->visit('/admin/login');
            $this->submitLogin($browser, 'admin@example.com', 'password')
                ->waitForText('Zu viele Loginversuche')
                ->assertPathIs('/admin/login');

            // Echte Wartezeit: Das Limit gilt 60 Sekunden.
            $browser->pause(61_000);

            $this->login($browser, 'admin@example.com')->assertPathIs('/admin');
        });
    }

    /** ANM-02 */
    public function test_login_returns_to_the_originally_requested_page(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/users')->waitFor(self::SELECTOR_EMAIL)->assertPathIs('/admin/login');

            $this->submitLogin($browser, 'admin@example.com')
                ->waitForLocation('/admin/users', 15)
                ->assertSee('Benutzer');
        });
    }

    /** ANM-03: Das Schließen des Browsers wird durch Löschen des Sitzungs-Cookies nachgestellt. */
    public function test_remember_me_survives_loss_of_the_session_cookie(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $withRemember, Browser $withoutRemember): void {
            $withRemember->visit('/admin/login')->waitFor(self::SELECTOR_EMAIL)->check('input[id="form.remember"]');
            $this->submitLogin($withRemember, 'admin@example.com')->waitFor(self::SELECTOR_SIDEBAR, 15);
            $this->loseSessionCookie($withRemember);
            $withRemember->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertPathIs('/admin');

            $this->login($withoutRemember, 'admin@example.com');
            $this->loseSessionCookie($withoutRemember);
            $withoutRemember->visit('/admin')->waitFor(self::SELECTOR_EMAIL)->assertPathIs('/admin/login');
        });
    }

    /**
     * Statische Seite zuerst: Sonst setzen Antworten noch ladender Widgets das Cookie wieder,
     * was beim echten Schließen des Browsers nicht passieren kann.
     */
    private function loseSessionCookie(Browser $browser): void
    {
        $browser->visit('/robots.txt');
        $browser->driver->manage()->deleteCookieNamed((string) config('session.cookie'));
    }

    /** ANM-07 */
    public function test_login_ignores_the_letter_case_of_the_email(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'ADMIN@Example.COM')->assertPathIs('/admin');
        });
    }

    /** ANM-08 */
    public function test_inactive_account_gets_the_same_message_as_a_wrong_password(): void
    {
        $this->superAdmin()->update(['is_active' => false]);

        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/login');
            $this->submitLogin($browser, 'admin@example.com')
                ->waitForText(self::TEXT_LOGIN_FAILED)
                ->assertPathIs('/admin/login')
                ->assertMissing(self::SELECTOR_SIDEBAR);
        });
    }

    /** ANM-09 */
    public function test_deactivation_takes_effect_in_a_running_session(): void
    {
        $this->superAdmin();
        $user = $this->standardUser();

        $this->browse(function (Browser $admin, Browser $affected) use ($user): void {
            $this->login($affected, 'benutzer@example.com')->assertPathIs('/admin');

            $this->login($admin, 'admin@example.com')
                ->visit("/admin/users/{$user->id}/edit")
                ->waitFor('[id="form.is_active"]', 15)
                ->click('[id="form.is_active"]')
                ->press('Speichern')
                ->waitForText('Gespeichert', 15);

            $this->assertFalse(User::findOrFail($user->id)->is_active);

            $affected->visit('/admin')->waitForText('Keine Berechtigung', 15)->assertMissing(self::SELECTOR_SIDEBAR);
        });
    }

    /** ANM-13 */
    public function test_user_changes_password_in_the_user_menu_and_stays_logged_in(): void
    {
        $this->standardUser();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'benutzer@example.com')
                ->click('.fi-user-menu-trigger')
                ->waitForText('Passwort ändern');
            $this->clickVisibleButton($browser, 'Passwort ändern')
                ->waitForText('Aktuelles Passwort', 10)
                ->type('input[id$="current_password"]', 'password')
                ->type('input[id$=".password"]', 'Neu-Passwort-456!')
                ->type('input[id$="password_confirmation"]', 'Neu-Passwort-456!');
            $this->clickVisibleButton($browser, 'Passwort ändern')
                ->waitForText('Passwort geändert', 10)
                // Die Sitzung bleibt gültig (AuthenticateSession vergleicht den Passwort-Hash).
                ->refresh()
                ->waitFor(self::SELECTOR_SIDEBAR, 15);

            $browser->assertPathIsNot('/admin/login');

            $this->logout($browser);
            $this->login($browser, 'benutzer@example.com', 'Neu-Passwort-456!')->assertPathIsNot('/admin/login');
        });
    }

    /** ANM-10 */
    public function test_data_is_not_usable_after_logout_and_back_button(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com')->visit('/admin/users')->waitForText('admin@example.com', 15);

            $this->logout($browser)->back();

            $browser->visit('/admin/users')
                ->waitFor(self::SELECTOR_EMAIL, 15)
                ->assertPathIs('/admin/login')
                ->assertDontSee('admin@example.com');
        });
    }

    /** ANM-11: Die App leitet direkt zur Anmeldeseite weiter, ohne Livewire-Fehlerdialog. */
    public function test_action_in_second_tab_after_logout_leads_to_login_without_error_dialog(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'admin@example.com')->visit('/admin/users')->waitForText('admin@example.com', 15);

            $tabA = $browser->driver->getWindowHandle();
            $browser->driver->switchTo()->newWindow();
            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15);
            $this->logout($browser);

            $browser->driver->switchTo()->window($tabA);
            $browser->type('.fi-ta-search-field input', 'admin')
                ->waitFor(self::SELECTOR_EMAIL, 15)
                ->assertPathIs('/admin/login')
                ->assertMissing('#livewire-error');

            $browser->refresh()->waitFor(self::SELECTOR_EMAIL, 15)->assertPathIs('/admin/login');
        });
    }

    private function requestResetLink(Browser $browser, string $email): string
    {
        $log = storage_path('logs/laravel.log');
        $offset = is_file($log) ? filesize($log) : 0;

        $browser->visit('/admin/password-reset/request')->waitFor(self::SELECTOR_EMAIL, 15)
            ->type(self::SELECTOR_EMAIL, $email)
            ->click(self::SELECTOR_SUBMIT)
            ->waitFor('.fi-no-notification', 15);

        $mail = (string) file_get_contents($log, offset: $offset);
        preg_match('#https?://[^"\s<]*password-reset/reset[^"\s<]*#', $mail, $link);
        $this->assertNotEmpty($link, 'Kein Link zum Zurücksetzen im Log gefunden.');

        return html_entity_decode($link[0]);
    }

    /** ANM-04: Die E-Mail wird aus dem Log gelesen (MAIL_MAILER=log), kein echter Versand. */
    public function test_password_reset_works_end_to_end(): void
    {
        $this->setAuthMode(AuthMode::Local, passwordReset: true);
        $this->superAdmin();
        $password = 'Neu-Geheim-2026!x';

        $this->browse(function (Browser $browser) use ($password): void {
            $browser->visit('/admin/login')->waitFor(self::SELECTOR_EMAIL, 15)->assertSee('Passwort vergessen');
            $link = $this->requestResetLink($browser, self::ADMIN_EMAIL);

            $browser->driver->get($link);
            $browser->waitFor('input[id="form.password"]', 15)
                ->type('input[id="form.password"]', $password)
                ->type('input[id="form.passwordConfirmation"]', $password)
                ->click(self::SELECTOR_SUBMIT)
                ->waitForLocation('/admin/login', 15);

            $this->submitLogin($browser, self::ADMIN_EMAIL)->waitForText(self::TEXT_LOGIN_FAILED, 15);
            $this->login($browser, self::ADMIN_EMAIL, $password)->assertPathIs('/admin');
        });
    }

    /** ANM-05 */
    public function test_reset_link_stops_working_when_the_option_is_disabled(): void
    {
        $this->setAuthMode(AuthMode::Local, passwordReset: true);
        $user = $this->superAdmin();
        $hash = $user->password;

        $this->browse(function (Browser $browser): void {
            $link = $this->requestResetLink($browser, self::ADMIN_EMAIL);
            $this->setAuthMode(AuthMode::Local, passwordReset: false);

            $browser->driver->get($link);
            $browser->waitForText('Nicht gefunden', 15)->assertMissing('input[id="form.password"]');
        });

        $this->assertSame($hash, $user->fresh()->password);
    }

    /** ANM-06 */
    public function test_reset_request_does_not_reveal_whether_an_account_exists(): void
    {
        $this->setAuthMode(AuthMode::Local, passwordReset: true);
        $this->superAdmin();
        $messages = [];

        $this->browse(function (Browser $known, Browser $unknown) use (&$messages): void {
            foreach ([[$known, self::ADMIN_EMAIL], [$unknown, 'gibt-es-nicht@example.com']] as [$browser, $email]) {
                $browser->visit('/admin/password-reset/request')->waitFor(self::SELECTOR_EMAIL, 15)
                    ->type(self::SELECTOR_EMAIL, $email)
                    ->click(self::SELECTOR_SUBMIT)
                    ->waitFor('.fi-no-notification', 15)
                    ->assertDontSee('Unerwarteter Fehler');
                $messages[] = trim($browser->text('.fi-no-notification'));
            }
        });

        $this->assertSame($messages[0], $messages[1]);
    }

    /** ANM-12 */
    public function test_break_glass_login_shows_the_password_form_with_hint(): void
    {
        $this->markTested($this->entraProvider());
        $this->setAuthMode(AuthMode::Sso);
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/login')->waitForText('Anmelden mit Firmen-Login', 15)->assertMissing(self::SELECTOR_EMAIL);

            $browser->visit('/admin/login?local=1')->waitFor(self::SELECTOR_EMAIL, 15)
                ->assertSee(__('auth_admin.login.break_glass_hint'))
                ->assertDontSee('Anmelden mit Firmen-Login');

            $this->submitLogin($browser, self::ADMIN_EMAIL)->waitFor(self::SELECTOR_SIDEBAR, 15)->assertPathIs('/admin');
        });
    }
}
