<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Filament\Resources\IdentityProviders\Pages\EditIdentityProvider;
use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class EntraDriverTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const string TOKEN_URL = 'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token';

    private IdentityProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->provider = $this->markTested($this->entraProvider());
        $this->setAuthMode(AuthMode::Hybrid);
    }

    public function test_redirect_uses_tenant_pkce_and_state(): void
    {
        $response = $this->get(route('auth.sso.redirect', $this->provider));

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?', $location);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $query['client_id']);
        $this->assertSame(route('auth.sso.callback', 'firma'), $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertEqualsCanonicalizing(['User.Read', 'openid', 'profile', 'email'], explode(' ', $query['scope']));
        $this->assertSame(session('state'), $query['state']);
        $this->assertNotEmpty(session('code_verifier'));
        $this->assertStringNotContainsString('super-geheim', $location);
    }

    public function test_custom_authority_host_is_used(): void
    {
        $this->provider->update(['settings' => [...$this->provider->settings, 'authority_host' => 'login.microsoftonline.us']]);
        $this->markTested($this->provider);

        $location = (string) $this->get(route('auth.sso.redirect', $this->provider))->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.us/contoso.onmicrosoft.com/', $location);
    }

    public function test_callback_exchanges_code_and_logs_in(): void
    {
        $user = User::factory()->create(['email' => 'max.muster@contoso.com']);
        $this->fakeMicrosoft(['mail' => 'Max.Muster@contoso.com']);

        $this->withSession(['state' => 'st4te', 'code_verifier' => 'verifier-123'])
            ->get(route('auth.sso.callback', ['identityProvider' => 'firma', 'code' => 'auth-code', 'state' => 'st4te']))
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);

        Http::assertSent(fn (Request $r): bool => $r->url() === self::TOKEN_URL
            && $r['code'] === 'auth-code'
            && $r['code_verifier'] === 'verifier-123'
            && $r['client_secret'] === 'super-geheim'
            && $r['redirect_uri'] === route('auth.sso.callback', 'firma'));
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://graph.microsoft.com/v1.0/me')
            && $r->hasHeader('Authorization', 'Bearer access-xyz'));

        $account = SocialAccount::query()->where('identity_provider_id', $this->provider->id)->firstOrFail();
        $this->assertSame('00000000-aaaa-bbbb-cccc-000000000001', $account->provider_user_id);
        $this->assertSame('max.muster@contoso.com', $account->provider_email);
        $this->assertNull($account->token, 'Tokens werden bewusst nicht gespeichert.');

        $this->assertSame('sso:firma', Activity::query()->where('event', 'login')->latest('id')->firstOrFail()->properties['method']);
    }

    public function test_upn_is_used_when_mail_is_missing(): void
    {
        $user = User::factory()->create(['email' => 'max@contoso.onmicrosoft.com']);
        $this->fakeMicrosoft(['mail' => null, 'userPrincipalName' => 'max@contoso.onmicrosoft.com']);

        $this->withSession(['state' => 's', 'code_verifier' => 'v'])
            ->get(route('auth.sso.callback', ['identityProvider' => 'firma', 'code' => 'c', 'state' => 's']));

        $this->assertAuthenticatedAs($user);
    }

    public function test_relogin_matches_by_object_id_even_if_email_changed(): void
    {
        $user = User::factory()->create(['email' => 'alt@contoso.com']);
        SocialAccount::create([
            'user_id' => $user->id, 'identity_provider_id' => $this->provider->id,
            'provider' => 'entra', 'provider_user_id' => '00000000-aaaa-bbbb-cccc-000000000001',
        ]);
        $this->fakeMicrosoft(['mail' => 'neu@contoso.com']);

        $this->withSession(['state' => 's', 'code_verifier' => 'v'])
            ->get(route('auth.sso.callback', ['identityProvider' => 'firma', 'code' => 'c', 'state' => 's']));

        $this->assertAuthenticatedAs($user);
    }

    public function test_state_mismatch_is_rejected(): void
    {
        $this->fakeMicrosoft();

        $this->withSession(['state' => 'richtig', 'code_verifier' => 'v'])
            ->get(route('auth.sso.callback', ['identityProvider' => 'firma', 'code' => 'c', 'state' => 'gefälscht']))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.entra.invalid_state'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function idpErrors(): array
    {
        return [
            'abgebrochen' => ['access_denied', 'auth_admin.entra.access_denied'],
            'sonstiger Fehler' => ['invalid_request', 'auth_admin.login.sso_unavailable'],
        ];
    }

    #[DataProvider('idpErrors')]
    public function test_idp_errors_show_friendly_message(string $error, string $messageKey): void
    {
        $this->withSession(['state' => 's'])
            ->get(route('auth.sso.callback', ['identityProvider' => 'firma', 'error' => $error, 'error_description' => 'AADSTS50105: geheime Details', 'state' => 's']))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __($messageKey));

        $this->assertGuest();
    }

    public function test_token_endpoint_failure_is_handled(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->withSession(['state' => 's', 'code_verifier' => 'v'])
            ->get(route('auth.sso.callback', ['identityProvider' => 'firma', 'code' => 'abgelaufen', 'state' => 's']))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.login.sso_unavailable'));

        $this->assertGuest();
    }

    public function test_provider_without_secret_is_not_offered_as_working(): void
    {
        $this->provider->update(['secrets' => []]);
        $this->markTested($this->provider);

        $this->get(route('auth.sso.redirect', $this->provider))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.login.sso_unavailable'));
    }

    public function test_multi_tenant_endpoints_are_rejected_in_form(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(EditIdentityProvider::class, ['record' => $this->provider->getRouteKey()])
            ->fillForm(['settings' => [...$this->provider->settings, 'tenant_id' => 'Organizations']])
            ->call('save')
            ->assertHasFormErrors(['settings.tenant_id']);
    }

    // --- Rollen aus dem ID-Token ------------------------------------------------------

    public function test_app_roles_from_id_token_are_mapped(): void
    {
        $user = $this->enableRoleSync('roles');
        $this->fakeMicrosoft(idToken: $this->idToken(['roles' => ['SAFT.Leitung']]));

        $this->microsoftCallback()->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->hasRole('leitung'));
    }

    public function test_groups_claim_is_mapped_case_insensitively(): void
    {
        $user = $this->enableRoleSync('groups', 'AAAA0000-1111-2222-3333-444444444444');
        $this->fakeMicrosoft(idToken: $this->idToken(['groups' => ['aaaa0000-1111-2222-3333-444444444444']]));

        $this->microsoftCallback()->assertRedirect('/admin');

        $this->assertTrue($user->fresh()->hasRole('leitung'));
    }

    public function test_group_overage_keeps_roles_unchanged(): void
    {
        $user = $this->enableRoleSync('groups', 'gruppe');
        $user->assignRole('leitung');
        $this->fakeMicrosoft(idToken: $this->idToken(['_claim_names' => ['groups' => 'src1'], '_claim_sources' => ['src1' => []]]));

        $this->microsoftCallback()->assertRedirect('/admin');

        $this->assertTrue($user->fresh()->hasRole('leitung'), 'Bei Überlauf dürfen keine Rollen entzogen werden.');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidIdTokens(): array
    {
        return [
            'fremde Audience' => [['aud' => '99999999-0000-0000-0000-000000000000']],
            'fremder Tenant' => [['tid' => 'ffffffff-0000-0000-0000-000000000000', 'iss' => 'https://login.microsoftonline.com/ffffffff-0000-0000-0000-000000000000/v2.0']],
            'abgelaufen' => [['exp' => time() - 3600]],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    #[DataProvider('invalidIdTokens')]
    public function test_invalid_id_token_rejects_login_when_role_sync_is_active(array $claims): void
    {
        $this->enableRoleSync('roles');
        $this->fakeMicrosoft(idToken: $this->idToken(['roles' => ['SAFT.Leitung'], ...$claims]));

        $this->microsoftCallback()
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.login.sso_unavailable'));

        $this->assertGuest();
    }

    public function test_role_sync_without_known_tenant_guid_fails_closed(): void
    {
        $this->enableRoleSync('roles');
        $this->provider->update(['settings' => [...$this->provider->settings, 'tenant_guid' => null]]);
        $this->fakeMicrosoft(idToken: $this->idToken(['roles' => ['SAFT.Leitung']]));

        $this->microsoftCallback()->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    private function enableRoleSync(string $claim, string $externalValue = 'SAFT.Leitung'): User
    {
        $role = Role::create(['name' => 'leitung']);
        // tenant_guid wie nach einem Verbindungstest (Tenant ist hier als Domain konfiguriert).
        $this->provider->update(['settings' => [...$this->provider->settings, 'role_sync' => true, 'role_claim' => $claim, 'tenant_guid' => '0a0b0c0d-1111-2222-3333-444455556666']]);
        $this->provider->roleMappings()->create(['external_value' => $externalValue, 'role_id' => $role->id]);

        return User::factory()->create(['email' => 'max.muster@contoso.com']);
    }

    private function microsoftCallback(): TestResponse
    {
        return $this->withSession(['state' => 's', 'code_verifier' => 'v'])
            ->get(route('auth.sso.callback', ['identityProvider' => 'firma', 'code' => 'c', 'state' => 's']));
    }

    /**
     * Unsigniertes JWT (der Treiber prüft Claims, nicht die Signatur – siehe EntraDriver).
     *
     * @param  array<string, mixed>  $claims
     */
    private function idToken(array $claims): string
    {
        $tenantId = '0a0b0c0d-1111-2222-3333-444455556666';
        $payload = [
            'aud' => '11111111-2222-3333-4444-555555555555',
            'iss' => "https://login.microsoftonline.com/{$tenantId}/v2.0",
            'tid' => $tenantId,
            'exp' => time() + 3600,
            'oid' => '00000000-aaaa-bbbb-cccc-000000000001',
            ...$claims,
        ];
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($payload).'.signature';
    }

    /**
     * @param  array<string, mixed>  $me
     */
    private function fakeMicrosoft(array $me = [], ?string $idToken = null): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(array_filter(['access_token' => 'access-xyz', 'token_type' => 'Bearer', 'expires_in' => 3600, 'id_token' => $idToken])),
            'https://graph.microsoft.com/v1.0/me*' => Http::response([
                'id' => '00000000-aaaa-bbbb-cccc-000000000001',
                'displayName' => 'Max Muster',
                'userPrincipalName' => 'max.muster@contoso.onmicrosoft.com',
                'mail' => 'max.muster@contoso.com',
                ...$me,
            ]),
        ]);
    }
}
