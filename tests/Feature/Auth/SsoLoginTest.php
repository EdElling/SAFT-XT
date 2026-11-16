<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Auth\Sso\IdentityProviderType;
use App\Auth\Sso\SsoDriver;
use App\Auth\Sso\SsoDriverRegistry;
use App\Auth\Sso\SsoIdentity;
use App\Auth\Sso\SsoUserResolver;
use App\Exceptions\BusinessRuleException;
use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    private IdentityProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->provider = $this->markTested($this->entraProvider());
        $this->setAuthMode(AuthMode::Hybrid);
    }

    // --- SsoUserResolver -------------------------------------------------------------

    public function test_existing_link_wins(): void
    {
        $user = User::factory()->create();
        SocialAccount::create(['user_id' => $user->id, 'identity_provider_id' => $this->provider->id, 'provider' => 'entra', 'provider_user_id' => 'oid-1']);

        $resolved = $this->resolve(new SsoIdentity('oid-1', 'ganz-andere@example.com', 'X'));

        $this->assertTrue($resolved->is($user));
    }

    public function test_links_existing_user_by_email_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'Max.Muster@Firma.de']);

        $resolved = $this->resolve(new SsoIdentity('oid-2', 'MAX.muster@firma.DE', 'Max'));

        $this->assertTrue($resolved->is($user));
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'identity_provider_id' => $this->provider->id, 'provider_user_id' => 'oid-2']);
    }

    public function test_unknown_user_is_rejected_without_auto_provisioning(): void
    {
        $this->expectExceptionObject(new BusinessRuleException(__('auth_admin.errors.not_registered')));

        $this->resolve(new SsoIdentity('oid-3', 'neu@firma.de', 'Neu'));
    }

    public function test_auto_provisioning_creates_user_with_default_role(): void
    {
        $role = Role::create(['name' => 'mitarbeiter']);
        $this->provider->update(['auto_provision' => true, 'default_role_id' => $role->id]);

        $user = $this->resolve(new SsoIdentity('oid-4', 'neu@firma.de', 'Neue Person'));

        $this->assertSame('Neue Person', $user->name);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasRole('mitarbeiter'));
    }

    public function test_link_by_email_can_be_disabled(): void
    {
        User::factory()->create(['email' => 'max@firma.de']);
        $this->provider->update(['link_by_email' => false]);

        $this->expectException(BusinessRuleException::class);

        $this->resolve(new SsoIdentity('oid-5', 'max@firma.de', 'Max'));
    }

    public function test_inactive_and_deleted_users_are_rejected(): void
    {
        $inactive = User::factory()->create(['email' => 'inaktiv@firma.de', 'is_active' => false]);
        $deleted = User::factory()->create(['email' => 'weg@firma.de']);
        $deleted->delete();

        $cases = [
            'inaktiv@firma.de' => ['oid-6', 'sso.inactive', __('auth_admin.errors.inactive')],
            'weg@firma.de' => ['oid-7', 'sso.removed', __('auth_admin.errors.removed')],
        ];

        foreach ($cases as $email => [$oid, $code, $message]) {
            try {
                $this->resolve(new SsoIdentity($oid, $email, null));
                $this->fail("Ablehnung erwartet für {$email}");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame(0, SocialAccount::count());
    }

    /** Gelöscht bleibt gesperrt (auch mit Auto-Provisionierung); erst endgültiges Löschen erlaubt ein neues Konto. */
    public function test_deleted_sso_user_gets_a_new_account_only_after_force_delete(): void
    {
        $this->provider->update(['auto_provision' => true]);
        $identity = new SsoIdentity('oid-8', 'wieder@firma.de', 'Wieder Da');
        $first = $this->resolve($identity);
        $first->delete();

        try {
            $this->resolve($identity);
            $this->fail('Ablehnung erwartet für ein gelöschtes Konto');
        } catch (BusinessRuleException $e) {
            $this->assertSame('sso.removed', $e->errorCode);
        }

        $first->forceDelete();
        $this->assertSame(0, SocialAccount::count());

        $second = $this->resolve($identity);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('wieder@firma.de', $second->email);
        $this->assertSame(1, SocialAccount::query()->where('user_id', $second->id)->count());
    }

    public function test_domain_restriction(): void
    {
        User::factory()->create(['email' => 'max@extern.de']);
        $this->provider->update(['allowed_domains' => ['Firma.de']]);

        try {
            $this->resolve(new SsoIdentity('oid-8', 'max@extern.de', null));
            $this->fail('Ablehnung erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertSame('sso.domain_not_allowed', $e->errorCode);
        }
    }

    // --- Ablauf über Controller (mit Fake-Treiber) -----------------------------------

    public function test_full_sso_flow_logs_in_and_audits_method(): void
    {
        $user = User::factory()->create(['email' => 'max@firma.de']);
        $this->fakeDriver(new SsoIdentity('oid-9', 'max@firma.de', 'Max', token: 'access-token'));

        $this->get(route('auth.sso.redirect', $this->provider))->assertRedirect('https://idp.test/authorize');
        $this->get(route('auth.sso.callback', $this->provider))->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $account = SocialAccount::where('provider_user_id', 'oid-9')->firstOrFail();
        $this->assertSame('access-token', $account->token);

        $login = Activity::query()->where('event', 'login')->where('causer_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('sso:firma', $login->properties['method']);
    }

    public function test_rejected_sso_login_returns_to_login_with_message(): void
    {
        $this->fakeDriver(new SsoIdentity('oid-10', 'unbekannt@firma.de', null));

        $this->get(route('auth.sso.callback', $this->provider))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.errors.not_registered'));

        $this->assertGuest();
    }

    public function test_protocol_errors_are_not_leaked(): void
    {
        $this->fakeDriver(new \RuntimeException('Signature invalid: geheime Details'));

        $this->get(route('auth.sso.callback', $this->provider))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.login.sso_unavailable'));
    }

    public function test_sso_is_unavailable_in_local_mode_or_for_disabled_provider(): void
    {
        $this->fakeDriver(new SsoIdentity('x', 'x@firma.de', null));

        $this->setAuthMode(AuthMode::Local);
        $this->get(route('auth.sso.redirect', $this->provider))->assertRedirect('/admin/login');

        $this->setAuthMode(AuthMode::Hybrid);
        $this->provider->update(['is_enabled' => false]);
        $this->get(route('auth.sso.redirect', $this->provider))->assertRedirect('/admin/login');
    }

    private function resolve(SsoIdentity $identity): User
    {
        return app(SsoUserResolver::class)->resolve($this->provider->refresh(), $identity);
    }

    private function fakeDriver(SsoIdentity|\Throwable $result): void
    {
        FakeSsoDriver::$result = $result;
        app(SsoDriverRegistry::class)->register(IdentityProviderType::Entra, FakeSsoDriver::class);
    }
}

class FakeSsoDriver implements SsoDriver
{
    public static SsoIdentity|\Throwable $result;

    public function redirect(IdentityProvider $provider, Request $request): Response
    {
        return redirect()->away('https://idp.test/authorize');
    }

    public function identity(IdentityProvider $provider, Request $request): SsoIdentity
    {
        return self::$result instanceof \Throwable ? throw self::$result : self::$result;
    }
}
