<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Settings\AuthSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class LoginModesTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    public function test_local_mode_shows_password_form_only(): void
    {
        $this->entraProvider();

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('E-Mail')
            ->assertDontSee('Anmelden mit Firmen-Login');
    }

    public function test_sso_mode_shows_only_provider_buttons(): void
    {
        $this->markTested($this->entraProvider());
        $this->setAuthMode(AuthMode::Sso);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Anmelden mit Firmen-Login')
            ->assertSee(route('auth.sso.redirect', 'firma'), escape: false)
            ->assertDontSee('Passwort');
    }

    /** Der SPA-Modus darf die Weiterleitung zum Identity Provider nicht per JavaScript nachladen (Browser blockiert sie). */
    public function test_sso_button_is_a_real_link_without_spa_navigation(): void
    {
        $this->markTested($this->entraProvider());
        $this->setAuthMode(AuthMode::Hybrid);

        $html = $this->get('/admin/login')->assertOk()->getContent();
        $url = preg_quote(route('auth.sso.redirect', 'firma'), '/');

        $this->assertMatchesRegularExpression('/<a[^>]*href="'.$url.'"[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*href="'.$url.'"[^>]*wire:navigate/', $html);
    }

    public function test_hybrid_mode_shows_both(): void
    {
        $this->entraProvider();
        $this->setAuthMode(AuthMode::Hybrid);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Anmelden mit Firmen-Login')
            ->assertSee('Passwort');
    }

    public function test_disabled_providers_are_not_offered(): void
    {
        $this->entraProvider(['is_enabled' => false]);
        $this->setAuthMode(AuthMode::Hybrid);

        $this->get('/admin/login')->assertDontSee('Anmelden mit Firmen-Login');
    }

    public function test_password_login_works_in_local_mode_and_is_audited(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345']);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Activity::query()->where('event', 'login')->where('causer_id', $user->id)
            ->get()->contains(fn (Activity $a): bool => $a->properties['method'] === 'password'));
    }

    public function test_password_login_is_rejected_in_sso_mode(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345']);
        $this->setAuthMode(AuthMode::Sso);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_break_glass_allows_super_admins_only(): void
    {
        $admin = User::factory()->create(['password' => 'Geheim-12345'])->assignRole(User::ROLE_SUPER_ADMIN);
        $normal = User::factory()->create(['password' => 'Geheim-12345']);
        $this->setAuthMode(AuthMode::Sso);

        Livewire::withQueryParams(['local' => 1])->test(Login::class)
            ->fillForm(['email' => $normal->email, 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();

        Livewire::withQueryParams(['local' => 1])->test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_break_glass_can_be_disabled(): void
    {
        $admin = User::factory()->create(['password' => 'Geheim-12345'])->assignRole(User::ROLE_SUPER_ADMIN);
        $this->setAuthMode(AuthMode::Sso, breakGlass: false);

        Livewire::withQueryParams(['local' => 1])->test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_break_glass_flag_cannot_be_set_by_the_client(): void
    {
        $this->setAuthMode(AuthMode::Sso);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Login::class)->set('breakGlass', true);
    }

    public function test_force_local_env_overrides_database(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345']);
        $this->setAuthMode(AuthMode::Sso);
        config(['auth.force_local' => true]);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_reset_is_only_available_when_enabled(): void
    {
        $this->get('/admin/password-reset/request')->assertNotFound();

        $this->setAuthMode(AuthMode::Local, passwordReset: true);
        $this->get('/admin/password-reset/request')->assertOk();

        $this->setAuthMode(AuthMode::Sso, passwordReset: true);
        $this->get('/admin/password-reset/request')->assertNotFound();
    }

    public function test_auth_mode_command(): void
    {
        $this->artisan('auth:mode', ['mode' => 'hybrid'])->assertSuccessful();
        $this->assertSame(AuthMode::Hybrid, app(AuthSettings::class)->refresh()->mode);

        $this->artisan('auth:mode', ['mode' => 'kaputt'])->assertExitCode(2);
    }
}
