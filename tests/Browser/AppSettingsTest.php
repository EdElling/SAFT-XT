<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Auth\AuthMode;
use App\Settings\AppSettings;
use Laravel\Dusk\Browser;
use Tests\Concerns\ConfiguresAuth;
use Tests\DuskTestCase;

/** App-Einstellungen und Branding (Bereich APP). */
class AppSettingsTest extends DuskTestCase
{
    use ConfiguresAuth;

    private const string ADMIN = 'admin@example.com';

    private const string NAME = 'input[id="form.app_name"]';

    private function rename(Browser $browser, string $name): Browser
    {
        return $browser->visit('/admin/app-settings')->waitFor(self::NAME, 15)
            ->clear(self::NAME)->type(self::NAME, $name)
            ->press('Änderungen speichern')
            ->waitUsing(15, 100, static fn (): bool => app(AppSettings::class)->refresh()->app_name === $name)
            ->pause(800);
    }

    /** APP-01 */
    public function test_display_name_applies_everywhere_without_logging_out(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);
            $this->rename($browser, 'Mein Portal');

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertSee('Mein Portal');
            $this->assertStringContainsString('Mein Portal', $browser->driver->getTitle());

            $browser->visit('/admin/users')->waitFor('.fi-ta-table', 15);
            $this->logout($browser)->assertSee('Mein Portal');
            $this->assertStringContainsString('Mein Portal', $browser->driver->getTitle());
        });
    }

    /** APP-02 */
    public function test_reset_mail_uses_the_display_name_as_sender(): void
    {
        $user = $this->superAdmin();
        $settings = app(AppSettings::class);
        $settings->app_name = 'Absender Portal';
        $settings->save();
        $this->setAuthMode(AuthMode::Local, passwordReset: true);
        $log = storage_path('logs/laravel.log');
        $offset = is_file($log) ? filesize($log) : 0;

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->visit('/admin/password-reset/request')->waitFor(self::SELECTOR_EMAIL, 15)->type(self::SELECTOR_EMAIL, $user->email)->click(self::SELECTOR_SUBMIT)->pause(2000);
        });

        $mail = (string) file_get_contents($log, offset: $offset);
        $this->assertStringContainsString('From: Absender Portal', $mail);
        $this->assertStringNotContainsString('Laravel', strstr($mail, 'From:') ?: '');
    }

    /** APP-03 */
    public function test_laravel_is_not_visible_to_users(): void
    {
        $this->standardUser();

        $this->browse(function (Browser $browser): void {
            $pages = [];

            $browser->visit('/')->waitFor(self::SELECTOR_EMAIL, 15);
            $pages['Anmeldeseite'] = $this->visibleText($browser);

            $this->login($browser, 'benutzer@example.com');
            $pages['Dashboard'] = $this->visibleText($browser);

            $browser->visit('/admin/gibtsnicht');
            $pages['Seite 404'] = $this->visibleText($browser);

            $browser->visit('/admin/users');
            $pages['Seite 403'] = $this->visibleText($browser);

            foreach ($pages as $page => $text) {
                $this->assertStringNotContainsStringIgnoringCase('laravel', $text, $page);
            }
        });
    }

    /** APP-04 */
    public function test_long_names_and_special_characters_display_correctly(): void
    {
        $this->superAdmin();
        $long = str_repeat('Portal ', 8).'Ende'; // 60 Zeichen

        $this->browse(function (Browser $browser) use ($long): void {
            $this->login($browser, self::ADMIN);

            $this->rename($browser, 'Müller & Söhne');
            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertSee('Müller & Söhne');
            $this->assertStringContainsString('Müller & Söhne', $browser->driver->getTitle());

            $this->rename($browser, $long);
            $this->assertSame(60, mb_strlen(app(AppSettings::class)->refresh()->app_name));
            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertPresent('.fi-topbar');
            [$overflow] = $browser->script('return document.documentElement.scrollWidth > window.innerWidth;');
            $this->assertFalse($overflow, 'Der lange Name darf die Seite nicht horizontal verbreitern.');

            $browser->visit('/admin/app-settings')->waitFor(self::NAME, 15);
            $this->assertSame('60', $browser->attribute(self::NAME, 'maxlength'));
        });
    }

    /** APP-05 */
    public function test_read_only_right_shows_the_page_without_saving(): void
    {
        $this->activeUser('leser@example.com')->givePermissionTo('settings.app.view');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'leser@example.com')->visit('/admin/app-settings')->waitFor(self::NAME, 15)
                ->assertDontSee('Änderungen speichern');
            $this->assertSame('true', $browser->attribute(self::NAME, 'disabled'));
        });
    }

    /** APP-06 */
    public function test_start_page_redirects_to_panel_or_login(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $browser->visit('/')->waitFor(self::SELECTOR_EMAIL, 15)->assertPathIs('/admin/login');
            $this->login($browser, self::ADMIN);
            $browser->visit('/')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertPathIs('/admin');
        });
    }
}
