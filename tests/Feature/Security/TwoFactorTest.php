<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Auth\Sso\SsoSession;
use App\Auth\TwoFactor;
use App\Auth\TwoFactorMode;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\AuthSettingsPage;
use App\Filament\Pages\MySettingsPage;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Providers\BrandingServiceProvider;
use App\Settings\AppSettings;
use App\Settings\AuthSettings;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** B-057: Zwei-Faktor-Anmeldung mit Authenticator-App – Pflicht nach Einstellung, Anmeldung, Zurücksetzen. */
class TwoFactorTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        Filament::setCurrentPanel('admin');
        config(['saft.two_factor_enforce' => true]);
    }

    private function mode(TwoFactorMode $mode): void
    {
        $settings = app(AuthSettings::class);
        $settings->two_factor = $mode;
        $settings->save();
    }

    private function setupUrl(): string
    {
        return (string) Filament::getSetUpRequiredMultiFactorAuthenticationUrl();
    }

    private function withSecret(User $user): string
    {
        $secret = AppAuthentication::make()->generateSecret();
        $user->saveAppAuthenticationSecret($secret);

        return $secret;
    }

    public function test_administration_must_set_up_by_default(): void
    {
        $this->assertSame(TwoFactorMode::Administration, app(AuthSettings::class)->two_factor, 'Vorgabe nach der Migration');

        $this->actingAs($this->superAdmin())->get('/admin')->assertRedirect($this->setupUrl());
        $this->actingAs($this->userWithPermissions(['roles.view']))->get('/admin')->assertRedirect($this->setupUrl());
        $this->actingAs($this->userWithPermissions(['reports.view']))->get('/admin')->assertOk();

        $this->actingAs($this->superAdmin())->get($this->setupUrl())->assertOk()->assertSee('Authenticator-App');
    }

    public function test_modes_everyone_and_optional(): void
    {
        $this->mode(TwoFactorMode::Everyone);
        $this->actingAs($this->userWithPermissions())->get('/admin')->assertRedirect($this->setupUrl());

        $this->mode(TwoFactorMode::Optional);
        $this->actingAs($this->superAdmin())->get('/admin')->assertOk();
    }

    public function test_set_up_accounts_sso_sessions_and_switch_are_exempt(): void
    {
        $admin = $this->superAdmin();
        $this->withSecret($admin);
        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->actingAs($this->superAdmin())->withSession([SsoSession::SESSION_KEY => ['provider' => 'x']])->get('/admin')->assertOk();

        $ssoOnly = $this->superAdmin();
        $ssoOnly->forceFill(['password' => null])->saveQuietly();
        $this->actingAs($ssoOnly)->get('/admin')->assertOk();

        config(['saft.two_factor_enforce' => false]);
        $this->actingAs($this->superAdmin())->get('/admin')->assertOk();
    }

    public function test_login_asks_for_the_code(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345']);
        $secret = $this->withSecret($user);

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'Geheim-12345'])
            ->call('authenticate');
        $this->assertGuest();

        $login->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
        $this->assertGuest();

        $login->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($user, $secret))->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_recovery_code_and_never_with_empty_fields(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345']);
        $this->withSecret($user);
        $user->saveAppAuthenticationRecoveryCodes(array_map(fn (string $code): string => Hash::make($code), ['eins-zwei', 'drei-vier']));

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'Geheim-12345'])
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true);

        $login->call('authenticate')->assertHasErrors();
        $this->assertGuest();

        $login->set('data.multiFactor.app.recoveryCode', 'falsch')->call('authenticate')->assertHasErrors();
        $this->assertGuest();

        $login->set('data.multiFactor.app.recoveryCode', 'drei-vier')->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertCount(1, $user->fresh()->getAppAuthenticationRecoveryCodes(), 'Benutzter Code ist verbraucht');
    }

    public function test_secret_is_encrypted_and_changes_are_logged(): void
    {
        $user = $this->userWithPermissions();
        $secret = $this->withSecret($user);

        $raw = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, $user->fresh()->getAppAuthenticationSecret());
        $this->assertArrayNotHasKey('app_authentication_secret', $user->fresh()->toArray());

        $user->saveAppAuthenticationSecret(null);
        $this->assertSame(['two_factor_enabled', 'two_factor_disabled'], Activity::query()->where('subject_id', $user->id)->where('log_name', 'auth')->orderBy('id')->pluck('event')->all());
    }

    public function test_authenticator_app_shows_the_name_from_the_app_settings(): void
    {
        $settings = app(AppSettings::class);
        $settings->app_name = 'Lager Muster AG';
        $settings->save();
        // Wie bei jedem Start der Anwendung: Name aus den App-Einstellungen übernehmen.
        (new BrandingServiceProvider($this->app))->boot();

        $provider = Filament::getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        $this->assertSame('Lager Muster AG', $provider->getBrandName());
    }

    public function test_my_settings_shows_the_section_for_password_accounts(): void
    {
        $this->actingAs($this->userWithPermissions())->get(MySettingsPage::getUrl())->assertOk()->assertSee('Zwei-Faktor-Anmeldung');

        $ssoOnly = $this->userWithPermissions();
        $ssoOnly->forceFill(['password' => null])->saveQuietly();
        $this->actingAs($ssoOnly)->get(MySettingsPage::getUrl())->assertOk()->assertDontSee('Zwei-Faktor-Anmeldung');
    }

    public function test_administration_resets_and_command_resets(): void
    {
        $admin = $this->superAdmin();
        $this->withSecret($admin);
        $user = $this->userWithPermissions();
        $this->withSecret($user);
        $user->saveAppAuthenticationRecoveryCodes(['x']);

        $this->actingAs($admin);
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])->callAction('resetTwoFactor');

        $this->assertFalse(app(TwoFactor::class)->isSetUp($user->fresh()));
        $this->assertNull($user->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertTrue(Activity::query()->where('event', 'two_factor_reset')->where('subject_id', $user->id)->where('causer_id', $admin->id)->exists());

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])->assertActionHidden('resetTwoFactor');

        $this->artisan('auth:two-factor-reset', ['email' => strtoupper($admin->email)])->assertSuccessful();
        $this->assertFalse(app(TwoFactor::class)->isSetUp($admin->fresh()));
    }

    public function test_auth_settings_page_saves_the_mode(): void
    {
        $admin = $this->superAdmin();
        $this->withSecret($admin);
        $this->actingAs($admin);

        Livewire::test(AuthSettingsPage::class)
            ->assertSchemaStateSet(['two_factor' => 'administration'])
            ->fillForm(['two_factor' => 'everyone'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(TwoFactorMode::Everyone, app(AuthSettings::class)->two_factor);
    }
}
