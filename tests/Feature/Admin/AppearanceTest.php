<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\AppSettingsPage;
use App\Filament\Pages\MySettingsPage;
use App\Filament\Support\PanelAppearance;
use App\Models\Activity;
use App\Settings\AppSettings;
use App\Support\ColorScheme;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Farbschema (App-Einstellungen, für alle) und persönlicher Kontrastmodus. */
class AppearanceTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const string WHITE = '#ffffff';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    public function test_indigo_is_the_default_scheme(): void
    {
        $this->assertSame('indigo', app(AppSettings::class)->color_scheme);
        $this->assertSame(['primary' => Color::Indigo], PanelAppearance::colors());
        $this->assertStringContainsString('--saft-highlight:var(--primary-500)', PanelAppearance::styles());
    }

    public function test_scheme_is_saved_audited_and_validated(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(AppSettingsPage::class)
            ->assertSee('Rot (HfH)')
            ->fillForm(['color_scheme' => 'hfh_red'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(AppSettingsPage::getUrl());

        $this->assertSame('hfh_red', app(AppSettings::class)->refresh()->color_scheme);
        $this->assertSame(ColorScheme::HfhRed, PanelAppearance::scheme());
        $this->assertSame('#BE1925', PanelAppearance::colors()['primary'][600]);
        $this->assertStringContainsString('--saft-highlight:#E74011', PanelAppearance::styles());

        $log = Activity::query()->where('log_name', 'settings.app')->latest('id')->firstOrFail();
        $this->assertSame(['color_scheme' => 'hfh_red'], $log->attribute_changes['attributes']);

        Livewire::test(AppSettingsPage::class)
            ->fillForm(['color_scheme' => 'pink'])
            ->call('save')
            ->assertHasFormErrors(['color_scheme']);
    }

    public function test_viewer_cannot_change_the_scheme(): void
    {
        $this->actingAs($this->userWithPermissions(['settings.app.view']));

        Livewire::test(AppSettingsPage::class)->fillForm(['color_scheme' => 'green'])->call('save');

        $this->assertSame('indigo', app(AppSettings::class)->refresh()->color_scheme);
    }

    /** Hauptfarbe: weiße Schrift auf Schaltflächen und farbige Schrift auf hellem und dunklem Grund (WCAG AA). */
    public function test_every_scheme_meets_aa_contrast(): void
    {
        foreach (ColorScheme::cases() as $scheme) {
            $palette = $scheme->palette();

            $this->assertGreaterThanOrEqual(4.5, Color::calculateContrastRatio($palette[600], self::WHITE), $scheme->value.' Schaltfläche');
            $this->assertGreaterThanOrEqual(4.5, Color::calculateContrastRatio($palette[600], Color::Zinc[50]), $scheme->value.' Text hell');
            $this->assertGreaterThanOrEqual(4.5, Color::calculateContrastRatio($palette[400], Color::Zinc[900]), $scheme->value.' Text dunkel');
        }
    }

    /** Im Kontrastmodus verschobene Abstufungen erreichen 7:1 (WCAG AAA). */
    public function test_high_contrast_shades_meet_aaa(): void
    {
        foreach ([...array_map(fn (ColorScheme $s): array => $s->palette(), ColorScheme::cases()), Color::Zinc] as $palette) {
            // Hell: 600 nimmt den Wert von 800 an, 500 den von 700.
            $this->assertGreaterThanOrEqual(7, Color::calculateContrastRatio($palette[800], self::WHITE));
            // Dunkel: 400 nimmt den Wert von 200 an.
            $this->assertGreaterThanOrEqual(7, Color::calculateContrastRatio($palette[200], Color::Zinc[900]));
        }

        // Grauer Hilfstext (gray-500 → 700) auf Weiß und im dunklen Modus (gray-500 → 300).
        $this->assertGreaterThanOrEqual(7, Color::calculateContrastRatio(Color::Zinc[700], self::WHITE));
        $this->assertGreaterThanOrEqual(7, Color::calculateContrastRatio(Color::Zinc[300], Color::Zinc[900]));
    }

    public function test_high_contrast_is_a_personal_setting_for_everyone(): void
    {
        $user = $this->userWithPermissions();
        $this->actingAs($user);

        $this->get('/admin/my-settings')->assertOk()->assertSee('Hoher Kontrast');
        // Vorgabe: automatisch nach Betriebssystem.
        $this->assertStringContainsString("c.add('saft-hc-auto')", PanelAppearance::headScript());

        Livewire::test(MySettingsPage::class)
            ->assertFormSet(['high_contrast' => 'auto'])
            ->fillForm(['high_contrast' => 'on'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Gespeichert');

        $this->assertTrue($user->fresh()->high_contrast);
        $this->assertTrue(Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->where('event', 'updated')->exists());

        $this->get('/admin')->assertOk()->assertSee("c.add('saft-hc')", false)->assertSee('html.saft-hc:not(.dark)', false);

        Livewire::test(MySettingsPage::class)->fillForm(['high_contrast' => 'off'])->call('save');
        $this->assertFalse($user->fresh()->high_contrast);
        $this->assertStringNotContainsString('c.add(', PanelAppearance::headScript());

        Livewire::test(MySettingsPage::class)->fillForm(['high_contrast' => 'auto'])->call('save');
        $this->assertNull($user->fresh()->high_contrast);

        Livewire::test(MySettingsPage::class)->fillForm(['high_contrast' => 'maybe'])->call('save')->assertHasFormErrors(['high_contrast']);
    }

    public function test_high_contrast_styles_cover_light_dark_and_system_preference(): void
    {
        $styles = PanelAppearance::styles();

        $this->assertMatchesRegularExpression('/html\.saft-hc:not\(\.dark\)\{[^}]*--gray-400:'.preg_quote(Color::Zinc[600], '/').'/', $styles);
        $this->assertMatchesRegularExpression('/html\.saft-hc\.dark\{[^}]*--gray-500:'.preg_quote(Color::Zinc[300], '/').'/', $styles);
        $this->assertStringContainsString('@media (prefers-contrast:more){html.saft-hc-auto:not(.dark)', $styles);
        $this->assertStringContainsString('--primary-600:', $styles);
    }

    public function test_guests_get_the_automatic_mode_and_no_settings_page(): void
    {
        $this->assertStringContainsString("c.add('saft-hc-auto')", PanelAppearance::headScript());
        $this->get('/admin/my-settings')->assertRedirect();
    }
}
