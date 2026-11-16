<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppLocale;
use BezhanSalleh\LanguageSwitch\Http\Livewire\LanguageSwitchComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    /** Diese Dateien stammen aus laravel-lang und decken nur Deutsch ab, Englisch liefert Laravel selbst. */
    private const array PACKAGE_FILES = ['actions', 'auth', 'http-statuses', 'pagination', 'passwords', 'validation'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    private function loginPageLocale(array $headers = []): string
    {
        $html = (string) $this->get('/admin/login', $headers)->assertOk()->getContent();
        preg_match('/<html[^>]*\blang="([^"]+)"/', $html, $match);

        return $match[1] ?? '';
    }

    public function test_default_is_used_without_any_hint(): void
    {
        $this->assertSame('de', $this->loginPageLocale(['Accept-Language' => '']));
    }

    public function test_browser_language_is_recognised_and_unknown_ones_are_skipped(): void
    {
        $this->assertSame('en', $this->loginPageLocale(['Accept-Language' => 'en-GB,en;q=0.9']));
        $this->flushSession();
        $this->assertSame('en', $this->loginPageLocale(['Accept-Language' => 'ja,en;q=0.8,de;q=0.5']));
        $this->flushSession();
        $this->assertSame('de', $this->loginPageLocale(['Accept-Language' => 'ja']));
    }

    public function test_french_and_italian_are_offered(): void
    {
        $this->assertSame(['de', 'en', 'fr', 'it'], AppLocale::available());
        $this->assertSame('fr', $this->loginPageLocale(['Accept-Language' => 'fr-CH,fr;q=0.9']));
        $this->flushSession();
        $this->assertSame('it', $this->loginPageLocale(['Accept-Language' => 'it-CH']));

        $value = Carbon::parse('2026-02-01 14:30');
        app()->setLocale('fr');
        $this->assertSame('01/02/2026 14:30', AppLocale::dateTime($value));
        $this->assertSame('Fermer', __('common.close'));
        app()->setLocale('it');
        $this->assertSame('Chiudi', __('common.close'));
        $this->assertSame('Il campo nome è richiesto.', __('validation.required', ['attribute' => 'nome']));
    }

    public function test_cookie_beats_browser_and_session_beats_cookie(): void
    {
        $this->withCookie(AppLocale::COOKIE, 'en');
        $this->assertSame('en', $this->loginPageLocale(['Accept-Language' => 'de']));

        $this->flushSession();
        $this->withSession(['locale' => 'de']);
        $this->assertSame('de', $this->loginPageLocale(['Accept-Language' => 'en']));
    }

    public function test_setting_of_the_user_beats_everything_else(): void
    {
        $user = $this->superAdmin();
        $user->forceFill(['locale' => 'en'])->save();

        $html = (string) $this->actingAs($user)
            ->withSession(['locale' => 'de'])
            ->withCookie(AppLocale::COOKIE, 'de')
            ->get('/admin', ['Accept-Language' => 'de'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertSame('en', session('locale'));
    }

    #[DataProvider('invalidLocales')]
    public function test_unknown_values_are_ignored(string $value): void
    {
        $this->withSession(['locale' => $value])->withCookie(AppLocale::COOKIE, $value);

        $this->assertSame('de', $this->loginPageLocale(['Accept-Language' => '']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidLocales(): array
    {
        return [
            'nicht angeboten' => ['es'],
            'Pfad' => ['../../etc/passwd'],
            'leer' => [''],
        ];
    }

    public function test_switch_stores_choice_at_the_user(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        Livewire::test(LanguageSwitchComponent::class)->call('changeLocale', 'en');

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_switch_rejects_values_from_the_browser_that_are_not_offered(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        Livewire::test(LanguageSwitchComponent::class)->call('changeLocale', 'xx');

        $this->assertNull($user->fresh()->locale);
        $this->get('/admin')->assertOk()->assertSee('lang="de"', false);
    }

    public function test_user_preference_is_used_for_mail(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->assertSame('en', $user->preferredLocale());
    }

    public function test_formats_follow_the_language(): void
    {
        $value = Carbon::parse('2026-02-01 14:30');

        app()->setLocale('de');
        $this->assertSame('01.02.2026', AppLocale::date($value));
        $this->assertSame('01.02.2026 14:30', AppLocale::dateTime($value));

        app()->setLocale('en');
        $this->assertSame('01/02/2026', AppLocale::date($value));
        $this->assertSame('01/02/2026 14:30', AppLocale::dateTime($value));
        $this->assertNull(AppLocale::date(null));
    }

    public function test_app_runs_in_its_configured_time_zone(): void
    {
        $this->assertSame('Europe/Zurich', config('app.timezone'));
        $this->assertSame('Europe/Zurich', now()->timezoneName);

        // Sommer- und Winterzeit gegenüber UTC.
        $this->assertSame('14:00', Carbon::parse('2026-07-01 12:00', 'UTC')->setTimezone(config('app.timezone'))->format('H:i'));
        $this->assertSame('13:00', Carbon::parse('2026-01-01 12:00', 'UTC')->setTimezone(config('app.timezone'))->format('H:i'));
    }

    public function test_every_language_has_every_own_text(): void
    {
        $files = array_diff(
            array_map(static fn (string $path): string => basename($path, '.php'), glob(lang_path('de/*.php')) ?: []),
            self::PACKAGE_FILES,
        );
        $this->assertContains('errors', $files);

        foreach (AppLocale::available() as $locale) {
            foreach ($files as $file) {
                $path = lang_path("{$locale}/{$file}.php");
                $this->assertFileExists($path);
                $keys = array_keys(Arr::dot(require $path));

                foreach (AppLocale::available() as $other) {
                    $missing = array_diff($keys, array_keys(Arr::dot(require lang_path("{$other}/{$file}.php"))));
                    $this->assertSame([], array_values($missing), "In {$other}/{$file}.php fehlen Schlüssel aus {$locale}.");
                }
            }
        }
    }

    /** Fehlende Filament-Texte stehen in lang/vendor; neue Lücken nach einem Update fallen hier auf. */
    public function test_filament_has_every_text_in_every_language(): void
    {
        $namespaces = [
            'actions' => 'filament-actions', 'filament' => 'filament-panels', 'forms' => 'filament-forms',
            'infolists' => 'filament-infolists', 'notifications' => 'filament-notifications',
            'query-builder' => 'filament-query-builder', 'schemas' => 'filament-schemas', 'support' => 'filament',
            'tables' => 'filament-tables', 'widgets' => 'filament-widgets',
        ];
        $missing = [];

        foreach ($namespaces as $package => $namespace) {
            $base = base_path("vendor/filament/{$package}/resources/lang/en");

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $group = substr((string) $file, strlen($base) + 1, -4);

                foreach (array_keys(Arr::dot(require (string) $file)) as $key) {
                    foreach (array_diff(AppLocale::available(), ['en']) as $locale) {
                        if (! Lang::hasForLocale("{$namespace}::{$group}.{$key}", $locale)) {
                            $missing[] = "{$locale}: {$namespace}::{$group}.{$key}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_every_language_defines_all_formats(): void
    {
        $keys = ['label', 'date', 'datetime', 'time', 'xlsx_date', 'xlsx_datetime', 'csv_delimiter', 'csv_decimal'];

        foreach ((array) config('locales.available') as $code => $settings) {
            $this->assertSame([], array_values(array_diff($keys, array_keys($settings))), "Sprache {$code}");
        }

        foreach (AppLocale::available() as $locale) {
            $this->assertNotSame($this->decimalOf($locale), $this->delimiterOf($locale), 'Gleiche Zeichen machen eine CSV-Datei unlesbar.');
        }
    }

    private function decimalOf(string $locale): string
    {
        return config("locales.available.{$locale}.csv_decimal");
    }

    private function delimiterOf(string $locale): string
    {
        return config("locales.available.{$locale}.csv_delimiter");
    }
}
