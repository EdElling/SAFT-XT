<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Auth\AuthConfiguration;
use App\Auth\AuthMode;
use App\Auth\Sso\IdentityProviderType;
use App\Auth\Sso\Saml\Certificates;
use App\Models\IdentityProvider;
use App\Settings\AuthSettings;
use Laravel\Dusk\Browser;
use Tests\Concerns\ConfiguresAuth;
use Tests\DuskTestCase;

/** Einstellungen Authentifizierung und IdP-Dialog (Bereich AUT). */
class AuthAdminTest extends DuskTestCase
{
    use ConfiguresAuth;

    private const string ADMIN = 'admin@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
    }

    private function field(string $name): string
    {
        return '[id="form.'.$name.'"]';
    }

    private function samlProvider(): IdentityProvider
    {
        return IdentityProvider::create([
            'type' => IdentityProviderType::Saml,
            'name' => 'Firmen-SAML',
            'slug' => 'firmen-saml',
            'is_enabled' => false,
            'settings' => [
                'idp_entity_id' => 'https://idp.example.com/alt',
                'idp_sso_url' => 'https://idp.example.com/sso',
                'idp_certificate' => Certificates::generateSelfSigned('idp.example.com')['certificate'],
                'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
                'sign_requests' => false,
                'attribute_email' => 'email',
            ],
        ]);
    }

    /** AUT-01 */
    public function test_sso_mode_needs_a_verified_provider(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/auth-settings')->waitForText('Anmeldemodus', 15)
                ->clickAtXPath("//label[contains(., '".AuthMode::Sso->label()."')]")
                ->pause(600)
                ->press('Änderungen speichern')
                ->waitForText(__('auth_admin.errors.sso_requires_verified_provider'), 15);

            $this->assertSame(AuthMode::Local, app(AuthSettings::class)->refresh()->mode);
        });
    }

    /** AUT-02 */
    public function test_password_reset_option_follows_the_mode_immediately(): void
    {
        $option = '„Passwort vergessen“ anbieten';

        $this->browse(function (Browser $browser) use ($option): void {
            $this->login($browser, self::ADMIN)->visit('/admin/auth-settings')->waitForText($option, 15)
                ->clickAtXPath("//label[contains(., '".AuthMode::Sso->label()."')]")
                ->waitUntilMissingText($option, 15)
                ->clickAtXPath("//label[contains(., '".AuthMode::Hybrid->label()."')]")
                ->waitForText($option, 15)
                ->assertSee($option);
        });
    }

    /** AUT-03 */
    public function test_last_provider_cannot_be_disabled_or_deleted_in_sso_mode(): void
    {
        $provider = $this->markTested($this->entraProvider());

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN);
            $this->setAuthMode(AuthMode::Sso);

            $browser->visit("/admin/identity-providers/{$provider->slug}/edit")->waitFor($this->field('is_enabled'), 15)
                ->click($this->field('is_enabled'))
                ->press('Speichern')
                ->waitForText('letzte aktive', 15);
            $this->assertTrue($provider->fresh()->is_enabled);

            $browser->visit("/admin/identity-providers/{$provider->slug}/edit")->waitFor($this->field('is_enabled'), 15);
            $this->clickVisibleButton($browser, 'Löschen')->waitForText('Abbrechen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen')->waitForText('letzte aktive', 15);
            $this->assertNotNull(IdentityProvider::query()->find($provider->id));
        });
    }

    /** AUT-04 */
    public function test_dialog_shows_only_matching_sections_and_locks_the_type_on_edit(): void
    {
        $provider = $this->entraProvider();

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN)->visit('/admin/identity-providers/create')->waitForText('Verzeichnis-ID (Tenant)', 15)
                ->assertDontSee('Werte für den Identity Provider')
                ->clickAtXPath("//label[contains(., '".IdentityProviderType::Saml->label()."')]")
                ->waitForText('Werte für den Identity Provider', 15)
                ->assertDontSee('Verzeichnis-ID (Tenant)');

            $browser->visit("/admin/identity-providers/{$provider->slug}/edit")->waitForText('Verzeichnis-ID (Tenant)', 15);
            $disabled = $browser->script("return [...document.querySelectorAll('input[id^=\"form.type\"]')].every(i => i.disabled);")[0];
            $this->assertTrue($disabled);
        });
    }

    /** AUT-05 */
    public function test_callback_url_follows_the_slug_and_copy_gives_the_plain_url(): void
    {
        $url = route('auth.sso.callback', 'neue-kennung');

        $this->browse(function (Browser $browser) use ($url): void {
            $this->login($browser, self::ADMIN)->visit('/admin/identity-providers/create')->waitFor($this->field('slug'), 15)
                ->type($this->field('slug'), 'neue-kennung')
                ->keys($this->field('slug'), '{tab}');

            $browser->waitForText($url, 15);

            $browser->script('window.__copied=[]; navigator.clipboard.writeText = (t) => { window.__copied.push(t); return Promise.resolve(); };');
            $browser->clickAtXPath("//*[contains(., 'Redirect-URI:') and contains(., 'neue-kennung')][not(.//*[contains(., 'Redirect-URI:')])]")->pause(500);

            $this->assertSame([$url], $browser->script('return window.__copied;')[0]);
        });
    }

    /** AUT-06 */
    public function test_stored_secret_is_never_sent_to_the_browser(): void
    {
        $provider = $this->entraProvider();

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN)->visit("/admin/identity-providers/{$provider->slug}/edit")
                ->waitFor($this->field('new_secrets.client_secret'), 15);

            $this->assertSame('', (string) $browser->value($this->field('new_secrets.client_secret')));
            $this->assertStringContainsString('Gespeichert', (string) $browser->attribute($this->field('new_secrets.client_secret'), 'placeholder'));
            $this->assertStringNotContainsString('super-geheim', $browser->driver->getPageSource());
        });

        $this->assertSame('super-geheim', $provider->fresh()->secrets['client_secret']);
    }

    /** AUT-07 */
    public function test_generated_sp_key_is_shown_until_saved_and_not_after_reload(): void
    {
        $provider = $this->samlProvider();
        $provider->update(['settings' => [...$provider->settings, 'sign_requests' => true]]);

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN)->visit("/admin/identity-providers/{$provider->slug}/edit")
                ->waitForText('SP-Zertifikat erzeugen', 15);

            $this->clickVisibleButton($browser, 'SP-Zertifikat erzeugen')->waitForText('Bestätigen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Bestätigen')->waitForText('bitte speichern', 15);

            $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $browser->value($this->field('settings.sp_certificate')));
            $this->assertStringContainsString('PRIVATE KEY', (string) $browser->value($this->field('new_secrets.sp_private_key')));

            $this->clickVisibleButton($browser, 'Speichern')->pause(1500);
            $browser->visit("/admin/identity-providers/{$provider->slug}/edit")->waitFor($this->field('settings.sp_certificate'), 15);

            $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $browser->value($this->field('settings.sp_certificate')));
            $this->assertSame('', (string) $browser->value($this->field('new_secrets.sp_private_key')));
            $this->assertStringNotContainsString('PRIVATE KEY', $browser->driver->getPageSource());
        });

        $this->assertTrue($provider->fresh()->hasSecret('sp_private_key'));
    }

    /** AUT-08 */
    public function test_connection_test_saves_the_form_first(): void
    {
        $provider = $this->samlProvider();

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN)->visit("/admin/identity-providers/{$provider->slug}/edit")
                ->waitFor($this->field('settings.idp_entity_id'), 15)
                ->assertSee('Noch nicht getestet')
                ->clear($this->field('settings.idp_entity_id'))
                ->type($this->field('settings.idp_entity_id'), 'https://idp.example.com/neu');

            // Der Button steht oben und läge sonst unter der festen Kopfleiste.
            $browser->script('window.scrollTo(0, 0);');
            $browser->pause(200);
            $this->clickVisibleButton($browser, 'Verbindung testen')->waitForText(__('auth_admin.test.success'), 15);

            $fresh = $provider->fresh();
            $this->assertSame('https://idp.example.com/neu', $fresh->settings['idp_entity_id']);
            $this->assertTrue($fresh->last_test_successful);

            $browser->assertDontSee('Noch nicht getestet');
        });
    }

    /** AUT-09 */
    public function test_role_mapping_rows_follow_the_source_and_reject_duplicates(): void
    {
        $provider = $this->entraProvider();

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN)->visit("/admin/identity-providers/{$provider->slug}/edit")
                ->waitFor($this->field('settings.role_sync'), 15)
                ->click($this->field('settings.role_sync'))
                ->waitForText('Zuordnung hinzufügen', 15);

            $this->clickVisibleButton($browser, 'Zuordnung hinzufügen')->waitForText('App-Rollen-Wert', 15);
            $browser->select($this->field('settings.role_claim'), 'groups')->waitForText('Gruppen-Object-ID', 15);

            $roles = $browser->script("return [...document.querySelector('select[id\$=\".role_id\"]').options].map(o => o.text.trim());")[0];
            $this->assertContains('benutzer', $roles);
            $this->assertNotContains('super-admin', $roles);

            $this->clickVisibleButton($browser, 'Zuordnung hinzufügen')->pause(900);
            $browser->script(<<<'JS'
                document.querySelectorAll('input[id$=".external_value"]').forEach(input => {
                    input.value = 'gleich';
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                });
                document.querySelectorAll('select[id$=".role_id"]').forEach(select => {
                    select.value = [...select.options].find(o => o.text.trim() === 'benutzer').value;
                    select.dispatchEvent(new Event('change', {bubbles: true}));
                });
            JS);
            $browser->pause(400);
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('darf nur einmal vorkommen', 15);

            // Zweite Zuordnung entfernen – per Skript, damit kein Tooltip oder Scrollen den Klick verschluckt
            // (Browser-Suite 24.11.2026: beide Zeilen blieben stehen), und warten, bis nur noch eine Zeile da ist.
            $browser->script("[...document.querySelectorAll('.fi-fo-repeater button[title]')].filter(b => b.title.includes('öschen'))[1]?.click();");
            $browser->waitUsing(15, 200, static fn (): bool => count($browser->elements('input[id$=".external_value"]')) === 1);
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);

            $this->assertSame(1, $provider->fresh()->roleMappings()->count());
        });
    }

    /** AUT-10 */
    public function test_email_name_id_format_shows_a_warning_immediately(): void
    {
        $provider = $this->samlProvider();

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN)->visit("/admin/identity-providers/{$provider->slug}/edit")
                ->waitFor($this->field('settings.name_id_format'), 15)
                ->assertDontSee('Mit dem Format „emailAddress“')
                ->select($this->field('settings.name_id_format'), 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress')
                ->waitForText('Mit dem Format „emailAddress“', 15);
        });
    }

    /** AUT-11 */
    public function test_invalid_certificate_gets_a_field_error(): void
    {
        $provider = $this->samlProvider();
        $certificate = $provider->settings['idp_certificate'];

        $this->browse(function (Browser $browser) use ($provider): void {
            $this->login($browser, self::ADMIN)->visit("/admin/identity-providers/{$provider->slug}/edit")
                ->waitFor($this->field('settings.idp_certificate'), 15)
                ->clear($this->field('settings.idp_certificate'))
                ->type($this->field('settings.idp_certificate'), 'kein zertifikat');

            $this->clickVisibleButton($browser, 'Speichern')->waitForText(__('auth_admin.test.saml_cert_invalid'), 15);
        });

        $this->assertSame($certificate, $provider->fresh()->settings['idp_certificate']);
        $this->assertSame(AuthMode::Local, app(AuthConfiguration::class)->mode());
    }
}
