<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use PragmaRX\Google2FA\Google2FA;
use Tests\DuskTestCase;

/**
 * Zwei-Faktor-Anmeldung mit Authenticator-App (Testliste ANM-14, ANM-15). Die Pflicht nach Einstellung prüfen die
 * Feature-Tests; hier ist sie aus (TWO_FACTOR_ENFORCE=false), eingerichtet wird freiwillig in „Meine Einstellungen“.
 */
class TwoFactorTest extends DuskTestCase
{
    private const string SELECTOR_CODE = '.fi-one-time-code-input-digit';

    /** Richtet die App in „Meine Einstellungen“ ein; liefert Geheimnis und Wiederherstellungscodes. */
    private function setUpApp(Browser $browser): array
    {
        $browser->visit('/admin/my-settings')
            ->waitForText('Zwei-Faktor-Anmeldung')
            ->press('Einrichten')
            ->waitForText('Authenticator-App einrichten');

        preg_match('/\b[A-Z2-7]{16}\b/', $this->visibleText($browser), $match);
        $this->assertNotEmpty($match, 'Geheimnis als Text für Apps ohne QR-Scan');
        $secret = $match[0];

        $browser->type('.fi-modal-window input[type="password"]', 'password')
            ->click('.fi-modal-window '.self::SELECTOR_CODE)
            ->keys('.fi-modal-window '.self::SELECTOR_CODE, (new Google2FA)->getCurrentOtp($secret))
            ->pause(300)
            ->press('Weiter')
            ->waitForText('Bitte speichern Sie die folgenden Wiederherstellungscodes', 15);

        preg_match_all('/\b[a-zA-Z0-9]{10}-[a-zA-Z0-9]{10}\b/', $this->visibleText($browser), $codes);
        $this->assertCount(8, $codes[0], 'Acht Wiederherstellungscodes');

        $browser->press('Authenticator-App aktivieren')
            ->waitForText('Authenticator-App wurde aktiviert', 15)
            ->waitForText('Aktiviert');
        // Die Erfolgsmeldung liegt über dem Benutzermenü; die Startseite ohne sie fürs Abmelden.
        $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15);

        return [$secret, $codes[0]];
    }

    /** ANM-14 */
    public function test_set_up_in_my_settings_and_sign_in_with_a_code(): void
    {
        $user = $this->superAdmin();

        $this->browse(function (Browser $browser) use ($user): void {
            $this->login($browser, $user->email);
            [$secret] = $this->setUpApp($browser);
            $this->logout($browser);

            $this->submitLogin($browser, $user->email)
                ->waitFor(self::SELECTOR_CODE, 15)
                ->assertMissing(self::SELECTOR_SIDEBAR)
                ->click(self::SELECTOR_CODE)
                ->keys(self::SELECTOR_CODE, '000000')
                ->waitForText('Der eingegebene Code ist ungültig.', 15)
                ->assertPathIs('/admin/login');

            // Neues Zeitfenster abwarten, damit der Code nicht derselbe ist wie beim Einrichten (Wiederverwendung gesperrt).
            $google2fa = new Google2FA;
            $browser->pause((30 - time() % 30 + 1) * 1000);
            $browser->script("document.querySelectorAll('".self::SELECTOR_CODE."').forEach(i => { i.value = ''; i.dispatchEvent(new Event('input')); })");
            $browser->click(self::SELECTOR_CODE)
                ->keys(self::SELECTOR_CODE, $google2fa->getCurrentOtp($secret))
                ->waitFor(self::SELECTOR_SIDEBAR, 15)
                ->assertPathIs('/admin');
        });

        $this->assertNotNull(User::query()->find($user->id)?->getAppAuthenticationSecret());
    }

    /** ANM-15 */
    public function test_sign_in_with_a_recovery_code_uses_it_up(): void
    {
        $user = $this->superAdmin();

        $this->browse(function (Browser $browser) use ($user): void {
            $this->login($browser, $user->email);
            [, $codes] = $this->setUpApp($browser);
            $this->logout($browser);

            $this->submitLogin($browser, $user->email)
                ->waitForText('Stattdessen einen Wiederherstellungscode verwenden', 15)
                ->press('Stattdessen einen Wiederherstellungscode verwenden')
                ->waitForText('Oder geben Sie einen Wiederherstellungscode ein')
                ->type('input[id$="recoveryCode"]', $codes[0])
                ->tap(fn (Browser $b) => $this->assertSame('', $this->nativeValidationMessage($b, self::SELECTOR_CODE), 'Codefeld darf nicht mehr Pflicht sein'))
                ->press('Anmeldung bestätigen')
                ->waitFor(self::SELECTOR_SIDEBAR, 15);
            $this->logout($browser);

            $this->submitLogin($browser, $user->email)
                ->waitForText('Stattdessen einen Wiederherstellungscode verwenden', 15)
                ->press('Stattdessen einen Wiederherstellungscode verwenden')
                ->waitForText('Oder geben Sie einen Wiederherstellungscode ein')
                ->type('input[id$="recoveryCode"]', $codes[0])
                ->press('Anmeldung bestätigen')
                ->waitForText('ungültig', 15)
                ->assertPathIs('/admin/login');
        });
    }
}
