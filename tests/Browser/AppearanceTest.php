<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\DynamicRecord;
use App\Models\User;
use App\Services\DynamicSchemaService;
use App\Settings\AppSettings;
use App\Support\ColorScheme;
use Filament\Support\Colors\Color;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Darstellung: Farbschema, hoher Kontrast und Kontrastprüfung mit axe-core (Bereich OBF). */
class AppearanceTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    /** Seiten der Kontrastprüfung: Dashboard, Admin-Liste, Formulare, Datenansicht mit ausgewählter Zeile. */
    private const array PAGES = ['/admin', '/admin/users', '/admin/app-settings', '/admin/my-settings', '/admin/data/kunden'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin();
    }

    private function scheme(ColorScheme $scheme): void
    {
        $settings = app(AppSettings::class);
        $settings->color_scheme = $scheme->value;
        $settings->save();
    }

    /** Hell oder dunkel wie über das Benutzermenü: gespeichert beim Benutzer, jede Seite übernimmt es. */
    private function theme(Browser $browser, string $theme): void
    {
        User::query()->update(['theme' => $theme]);
        $browser->script("localStorage.setItem('filament:theme:mode', '{$theme}');");
    }

    /**
     * Kontrastverstöße laut axe-core (Regel color-contrast = AA, color-contrast-enhanced = AAA).
     *
     * @return list<string>
     */
    private function contrastViolations(Browser $browser, string $rule): array
    {
        $browser->script((string) file_get_contents(base_path('node_modules/axe-core/axe.min.js')));
        $browser->driver->manage()->timeouts()->setScriptTimeout(30);
        $result = $browser->driver->executeAsyncScript(
            'const done = arguments[arguments.length - 1];'
            ."axe.run(document, {runOnly: ['{$rule}']}).then(r => done(r.violations.flatMap(v => v.nodes.map(n => n.target.join(' ') + ' | ' + (n.any[0]?.message ?? '')))));",
        );

        return array_values((array) $result);
    }

    /** Datentabelle mit ausgewählter Zeile (Markierung im Farbschema). */
    private function customers(): void
    {
        $table = app(DynamicSchemaService::class)->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_searchable' => true],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string'],
        ]);

        foreach ([['ACME GmbH', 'Zürich'], ['Beta AG', 'Bern'], ['Gamma SA', 'Genf']] as [$firma, $ort]) {
            DynamicRecord::forTable($table)->create(['firma' => $firma, 'ort' => $ort]);
        }
    }

    private function style(Browser $browser, string $selector, string $property): string
    {
        return (string) $browser->script("return getComputedStyle(document.querySelector(\"{$selector}\")).getPropertyValue('{$property}');")[0];
    }

    /** OBF-12 */
    public function test_dark_mode_belongs_to_the_user_not_the_browser(): void
    {
        $this->activeUser('zweite@example.com');
        $dark = fn (Browser $b): bool => (bool) $b->script('return document.documentElement.classList.contains("dark");')[0];

        $this->browse(function (Browser $browser) use ($dark): void {
            $this->login($browser, 'admin@example.com')
                ->click('.fi-user-menu-trigger')
                ->waitFor('.fi-theme-switcher-btn', 5)
                ->click('.fi-theme-switcher button:nth-child(2)')
                ->waitUsing(10, 100, fn (): bool => $dark($browser) && User::query()->where('email', 'admin@example.com')->value('theme') === 'dark');

            // Anderer Benutzer im selben Browser: seine eigene Wahl (noch keine: System = hier hell).
            $this->logout($browser);
            $this->login($browser, 'zweite@example.com');
            $this->assertFalse($dark($browser));

            $this->logout($browser);
            $this->login($browser, 'admin@example.com');
            $this->assertTrue($dark($browser));
        });
    }

    /** OBF-08 */
    public function test_color_scheme_applies_to_buttons_and_selected_row(): void
    {
        $this->customers();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/app-settings')->waitForText('Farbschema', 15)
                ->assertSee('Rot (HfH)')
                ->click('input[type=radio][value="hfh_red"]');
            $browser->press('Änderungen speichern')->waitForText('Gespeichert', 15);
            $this->assertSame('hfh_red', app(AppSettings::class)->refresh()->color_scheme);

            // Schaltfläche in #BE1925, Markierung der ausgewählten Zeile in der Grundfarbe #E74011.
            $this->assertSame(Color::convertToOklch('#BE1925'), $this->style($browser, 'button[type=submit].fi-btn', 'background-color'));

            $browser->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
            $this->clickRow($browser, 'ACME GmbH');
            $browser->waitFor('.saft-row-selected', 15);
            $this->assertStringContainsString('rgb(231, 64, 17)', $this->style($browser, '.saft-row-selected > td', 'box-shadow'));
        });
    }

    /** OBF-09 */
    public function test_high_contrast_is_chosen_per_person(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin');
            $this->assertSame('fi saft-hc-auto', $browser->script('return document.documentElement.className')[0]);

            $browser->click('.fi-user-menu-trigger')->waitForText('Meine Einstellungen', 15)
                ->clickLink('Meine Einstellungen')
                ->waitForText('Hoher Kontrast', 15)
                ->click('input[type=radio][value="on"]')
                ->press('Speichern')->waitForText('Gespeichert', 15);

            $this->assertStringContainsString('saft-hc', (string) $browser->script('return document.documentElement.className')[0]);
            $this->assertTrue(User::query()->where('email', self::ADMIN)->value('high_contrast'));

            // Gilt auf allen Seiten und nach dem Neuladen: Hilfstexte (gray-500) so dunkel wie sonst gray-700.
            $browser->visit('/admin/users')->waitForText(self::ADMIN, 15);
            $this->assertSame('fi saft-hc', $browser->script('return document.documentElement.className')[0]);
            $this->assertSame(Color::Zinc[700], trim($this->style($browser, 'html', '--gray-500')));

            $browser->visit('/admin/my-settings')->waitForText('Hoher Kontrast', 15)
                ->click('input[type=radio][value="off"]')
                ->press('Speichern')->waitForText('Gespeichert', 15);
            $this->assertSame('fi', $browser->script('return document.documentElement.className')[0]);
        });
    }

    /** OBF-10: Kontrast in allen Farbschemata, hell und dunkel: normal AA (4.5:1), hoher Kontrast AAA (7:1). */
    public function test_all_schemes_meet_contrast_requirements(): void
    {
        $this->customers();
        $problems = [];

        foreach ([false, true] as $highContrast) {
            User::query()->where('email', self::ADMIN)->update(['high_contrast' => $highContrast]);

            foreach (ColorScheme::cases() as $scheme) {
                $this->scheme($scheme);

                $this->browse(function (Browser $browser) use ($highContrast, $scheme, &$problems): void {
                    $this->login($browser, self::ADMIN);

                    foreach (['light', 'dark'] as $theme) {
                        $this->theme($browser, $theme);

                        foreach (self::PAGES as $page) {
                            $browser->visit($page)->pause(800);

                            if ($page === '/admin/data/kunden') {
                                $this->clickRow($browser, 'ACME GmbH')->waitFor('.saft-row-selected', 15);
                            }

                            foreach ($this->contrastViolations($browser, $highContrast ? 'color-contrast-enhanced' : 'color-contrast') as $violation) {
                                $problems[] = ($highContrast ? 'hoher Kontrast' : 'normal')." {$scheme->value} {$theme} {$page}: {$violation}";
                            }
                        }
                    }
                });
                static::closeAll();
            }
        }

        $this->assertSame([], $problems);
    }
}
