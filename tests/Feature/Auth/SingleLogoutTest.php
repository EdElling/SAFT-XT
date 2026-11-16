<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Auth\Sso\Drivers\SamlDriver;
use App\Auth\Sso\Saml\Certificates;
use App\Auth\Sso\SsoSession;
use App\Models\IdentityProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Support\SamlTestIdp;
use Tests\TestCase;

class SingleLogoutTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    private SamlTestIdp $idp;

    private IdentityProvider $saml;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->setAuthMode(AuthMode::Hybrid);

        $this->idp = new SamlTestIdp;
        $sp = Certificates::generateSelfSigned('sp.test');

        $this->saml = $this->markTested(IdentityProvider::create([
            'type' => 'saml',
            'name' => 'Firmen-SAML',
            'slug' => 'firma-saml',
            'is_enabled' => true,
            'settings' => [
                'idp_entity_id' => SamlTestIdp::ENTITY_ID,
                'idp_sso_url' => SamlTestIdp::SSO_URL,
                'idp_slo_url' => SamlTestIdp::SLO_URL,
                'idp_certificate' => $this->idp->certificate(),
                'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
                'attribute_email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                'sign_requests' => true,
                'sp_certificate' => $sp['certificate'],
                'single_logout' => true,
            ],
            'secrets' => ['sp_private_key' => $sp['private_key']],
        ]));

        $this->user = User::factory()->create(['email' => 'max@firma.de']);
    }

    public function test_saml_logout_sends_signed_request_with_session_index(): void
    {
        $this->loginViaSaml();

        $location = (string) $this->post('/admin/logout')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertGuest();
        $this->assertStringStartsWith(SamlTestIdp::SLO_URL.'?', $location);
        $this->assertArrayHasKey('Signature', $query);

        $xml = (string) gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertStringContainsString('LogoutRequest', $xml);
        $this->assertStringContainsString('Format="urn:oasis:names:tc:SAML:2.0:nameid-format:persistent">persistent-id-123</', $xml);
        $this->assertMatchesRegularExpression('/SessionIndex>_session-[0-9a-f]+</', $xml);
        $this->assertSame($query['RelayState'], session(SamlDriver::LOGOUT_SESSION_KEY)['relay_state']);
    }

    public function test_idp_logout_response_is_confirmed(): void
    {
        $this->loginViaSaml();
        $this->post('/admin/logout');
        $pending = session(SamlDriver::LOGOUT_SESSION_KEY);

        $this->get($this->idp->logoutResponseUrl($this->saml->slsUrl(), $pending['request_id'], $pending['relay_state']))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_info', __('auth_admin.logout.completed'));
    }

    public function test_unrelated_logout_response_is_not_confirmed(): void
    {
        $this->loginViaSaml();
        $this->post('/admin/logout');

        $this->get($this->idp->logoutResponseUrl($this->saml->slsUrl(), '_fremde-id', null))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_info', __('auth_admin.logout.idp_unconfirmed'));
    }

    public function test_idp_initiated_logout_terminates_matching_session(): void
    {
        $this->loginViaSaml();

        $response = $this->get($this->idp->logoutRequestUrl($this->saml->slsUrl(), 'persistent-id-123'));

        $this->assertGuest();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(SamlTestIdp::SLO_URL.'?', $location);
        $this->assertArrayHasKey('Signature', $query, 'LogoutResponse muss signiert sein.');
        $this->assertStringContainsString('LogoutResponse', (string) gzinflate(base64_decode($query['SAMLResponse'])));

        $logout = Activity::query()->where('event', 'logout')->latest('id')->firstOrFail();
        $this->assertSame('idp:firma-saml', $logout->properties['method']);
    }

    public function test_idp_initiated_logout_for_other_identity_keeps_session(): void
    {
        $this->loginViaSaml();

        $response = $this->get($this->idp->logoutRequestUrl($this->saml->slsUrl(), 'jemand-anderes'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertStringStartsWith(SamlTestIdp::SLO_URL, (string) $response->headers->get('Location'));
    }

    public function test_forged_or_unsigned_logout_requests_are_ignored(): void
    {
        $this->loginViaSaml();

        $forged = $this->idp->logoutRequestUrl($this->saml->slsUrl(), 'persistent-id-123', Certificates::generateSelfSigned('evil'));
        $this->get($forged)->assertRedirect('/admin/login');
        $this->assertAuthenticatedAs($this->user);

        $unsigned = $this->idp->logoutRequestUrl($this->saml->slsUrl(), 'persistent-id-123', signed: false);
        $this->get($unsigned)->assertRedirect('/admin/login');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_disabled_single_logout_only_logs_out_locally(): void
    {
        $this->saml->update(['settings' => [...$this->saml->settings, 'single_logout' => false]]);
        $this->loginViaSaml();

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertGuest();
        $this->assertSame('sso:firma-saml', Activity::query()->where('event', 'logout')->latest('id')->firstOrFail()->properties['method']);
    }

    public function test_entra_logout_redirects_to_v2_endpoint(): void
    {
        $entra = $this->entraProvider(['settings' => [
            'tenant_id' => 'contoso.onmicrosoft.com', 'client_id' => '11111111-2222-3333-4444-555555555555', 'single_logout' => true,
        ]]);
        $session = new SsoSession($entra->id, $entra->slug, 'oid-1');

        $location = (string) $this->actingAs($this->user)
            ->withSession([SsoSession::SESSION_KEY => $session->toArray()])
            ->post('/admin/logout')
            ->headers->get('Location');

        $this->assertSame(
            'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/logout?post_logout_redirect_uri='.urlencode(url('/admin/login')),
            $location,
        );
        $this->assertGuest();
    }

    public function test_local_logout_is_audited_as_local(): void
    {
        $this->actingAs($this->user)->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertSame('local', Activity::query()->where('event', 'logout')->latest('id')->firstOrFail()->properties['method']);
    }

    public function test_toggling_single_logout_keeps_connection_test(): void
    {
        $this->saml->update(['settings' => [...$this->saml->settings, 'single_logout' => false]]);

        $this->assertTrue($this->saml->refresh()->last_test_successful);
    }

    public function test_sp_metadata_announces_redirect_sls_only(): void
    {
        $xml = (string) $this->get(route('auth.saml.metadata', $this->saml))->getContent();

        $this->assertStringContainsString('SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="'.$this->saml->slsUrl().'"', $xml);
        $this->assertStringNotContainsString('SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"', $xml);
    }

    private function loginViaSaml(): void
    {
        $this->get(route('auth.sso.redirect', $this->saml))->assertRedirect();

        $saml = $this->idp->response(route('auth.saml.acs', 'firma-saml'), $this->saml->spEntityId(), [
            'inResponseTo' => (string) session('saml2_request_id'),
        ]);

        $handover = $this->post(route('auth.saml.acs', $this->saml), ['SAMLResponse' => $saml, 'RelayState' => (string) session('state')]);
        $this->get((string) $handover->headers->get('Location'))->assertRedirect('/admin');
        $this->assertAuthenticatedAs($this->user);
    }
}
