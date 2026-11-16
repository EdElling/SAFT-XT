<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Auth\Sso\Saml\Certificates;
use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Support\SamlTestIdp;
use Tests\TestCase;

class SamlDriverTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    private SamlTestIdp $idp;

    private IdentityProvider $provider;

    /** @var array{certificate: string, private_key: string} */
    private array $spKeyPair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();

        $this->idp = new SamlTestIdp;
        $this->spKeyPair = Certificates::generateSelfSigned('sp.test');

        $this->provider = $this->markTested(IdentityProvider::create([
            'type' => 'saml',
            'name' => 'Firmen-SAML',
            'slug' => 'firma-saml',
            'is_enabled' => true,
            'settings' => [
                'idp_entity_id' => SamlTestIdp::ENTITY_ID,
                'idp_sso_url' => SamlTestIdp::SSO_URL,
                'idp_certificate' => $this->idp->certificate(),
                'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
                'attribute_email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                'attribute_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                'sign_requests' => true,
                'sp_certificate' => $this->spKeyPair['certificate'],
            ],
            'secrets' => ['sp_private_key' => $this->spKeyPair['private_key']],
        ]));

        $this->setAuthMode(AuthMode::Hybrid);
    }

    public function test_redirect_sends_signed_authn_request(): void
    {
        $location = (string) $this->get(route('auth.sso.redirect', $this->provider))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(SamlTestIdp::SSO_URL.'?', $location);
        $this->assertArrayHasKey('SAMLRequest', $query);
        $this->assertArrayHasKey('Signature', $query, 'AuthnRequest muss signiert sein.');
        $this->assertSame(session('state'), $query['RelayState']);
        $this->assertNotEmpty(session('saml2_request_id'));

        $xml = (string) gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertStringContainsString('AssertionConsumerServiceURL="'.route('auth.saml.acs', 'firma-saml').'"', $xml);
        $this->assertStringContainsString($this->provider->spEntityId(), $xml);
    }

    public function test_full_login_flow(): void
    {
        $user = User::factory()->create(['email' => 'max@firma.de']);

        $complete = $this->postAcs($this->validResponse());
        $complete->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $account = SocialAccount::query()->where('identity_provider_id', $this->provider->id)->firstOrFail();
        $this->assertSame('persistent-id-123', $account->provider_user_id);
        $this->assertSame('sso:firma-saml', Activity::query()->where('event', 'login')->latest('id')->firstOrFail()->properties['method']);
    }

    public function test_acs_post_does_not_touch_session_cookie(): void
    {
        $this->startLogin();

        $response = $this->post(route('auth.saml.acs', $this->provider), ['SAMLResponse' => 'x', 'RelayState' => 's']);

        $response->assertStatus(303);
        $this->assertStringContainsString('/auth/saml/firma-saml/acs/', (string) $response->headers->get('Location'));
        $this->assertEmpty($response->headers->getCookies(), 'Stufe 1 darf keine Cookies setzen.');
    }

    public function test_handover_key_is_single_use(): void
    {
        User::factory()->create(['email' => 'max@firma.de']);
        [$saml, $relay] = $this->validResponse();

        $handover = $this->post(route('auth.saml.acs', $this->provider), ['SAMLResponse' => $saml, 'RelayState' => $relay]);
        $completeUrl = (string) $handover->headers->get('Location');

        $this->get($completeUrl)->assertRedirect('/admin');
        auth()->logout();

        $this->get($completeUrl)
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.saml.invalid_state'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function tamperedResponses(): array
    {
        return [
            'fremde Audience' => [['audience' => 'https://andere-app.example.com']],
            'falscher Recipient' => [['recipient' => 'https://evil.example.com/acs']],
            'abgelaufen' => [['notOnOrAfter' => '-10 minutes']],
            'fremder Issuer' => [['issuer' => 'https://evil-idp.example.com']],
            'fremde Signatur' => [['signingKey' => 'other']],
        ];
    }

    /**
     * @param  array<string, mixed>  $tamper
     */
    #[DataProvider('tamperedResponses')]
    public function test_tampered_responses_are_rejected(array $tamper): void
    {
        User::factory()->create(['email' => 'max@firma.de']);

        if (($tamper['signingKey'] ?? null) === 'other') {
            $tamper['signingKey'] = Certificates::generateSelfSigned('evil');
        }

        $this->postAcs($this->validResponse($tamper))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error', __('auth_admin.login.sso_unavailable'));

        $this->assertGuest();
    }

    public function test_unsolicited_idp_initiated_response_is_rejected(): void
    {
        User::factory()->create(['email' => 'max@firma.de']);
        $saml = $this->idp->response(route('auth.saml.acs', 'firma-saml'), $this->provider->spEntityId(), ['inResponseTo' => null]);

        $handover = $this->post(route('auth.saml.acs', $this->provider), ['SAMLResponse' => $saml]);

        $this->get((string) $handover->headers->get('Location'))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('sso_error');

        $this->assertGuest();
    }

    public function test_response_for_another_session_is_rejected(): void
    {
        User::factory()->create(['email' => 'max@firma.de']);
        $this->startLogin();

        // Antwort auf eine Anfrage, die nicht aus dieser Sitzung stammt.
        $saml = $this->idp->response(route('auth.saml.acs', 'firma-saml'), $this->provider->spEntityId(), ['inResponseTo' => '_fremde-request-id']);
        $handover = $this->post(route('auth.saml.acs', $this->provider), ['SAMLResponse' => $saml, 'RelayState' => (string) session('state')]);

        $this->get((string) $handover->headers->get('Location'))->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_replayed_response_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'max@firma.de']);
        [$saml, $relay] = $this->validResponse();
        $requestId = (string) session('saml2_request_id');

        $this->postAcs([$saml, $relay])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        auth()->logout();

        // Session-Bindung künstlich wiederherstellen, damit ausschließlich der
        // Replay-Schutz (Assertion-ID bereits verwendet) greifen kann.
        session()->put(['state' => $relay, 'saml2_request_id' => $requestId]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = isset($e->context['exception']) ? $e->context['exception']->getMessage() : $e->message;
        });

        $this->postAcs([$saml, $relay])->assertRedirect('/admin/login');

        $this->assertGuest();
        $this->assertContains('The identity provider repeated an assertion id', $logged);
    }

    public function test_multi_valued_group_attribute_is_mapped(): void
    {
        $role = Role::create(['name' => 'leitung']);
        $this->provider->update(['settings' => [...$this->provider->settings, 'role_sync' => true, 'role_attribute' => SamlTestIdp::GROUPS_ATTRIBUTE]]);
        $this->provider->roleMappings()->create(['external_value' => 'CN=SAFT-Leitung,OU=Gruppen,DC=firma,DC=de', 'role_id' => $role->id]);
        $user = User::factory()->create(['email' => 'max@firma.de']);

        $this->postAcs($this->validResponse(['groups' => ['CN=Alle,DC=firma,DC=de', 'cn=saft-leitung,ou=gruppen,dc=firma,dc=de']]))
            ->assertRedirect('/admin');

        $this->assertTrue($user->fresh()->hasRole('leitung'));
    }

    public function test_sp_metadata_contains_acs_entity_and_certificate(): void
    {
        $response = $this->get(route('auth.saml.metadata', $this->provider))->assertOk();

        $xml = (string) $response->getContent();
        $this->assertStringContainsString('application/samlmetadata+xml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('entityID="'.$this->provider->spEntityId().'"', $xml);
        $this->assertStringContainsString('Location="'.route('auth.saml.acs', 'firma-saml').'"', $xml);
        $this->assertStringContainsString('AuthnRequestsSigned="true"', $xml);

        $certBody = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n"], '', $this->spKeyPair['certificate']));
        $this->assertStringContainsString($certBody, str_replace(["\n", "\r", ' '], '', $xml));
        $this->assertStringNotContainsString('PRIVATE KEY', $xml);
    }

    public function test_idp_metadata_url_is_supported(): void
    {
        Http::fake(['idp.test/metadata' => Http::response($this->idp->metadataXml())]);
        $this->provider->update(['settings' => [...$this->provider->settings, 'idp_metadata_url' => 'https://idp.test/metadata', 'idp_certificate' => null, 'idp_sso_url' => null]]);
        $this->markTested($this->provider);
        $user = User::factory()->create(['email' => 'max@firma.de']);

        $this->postAcs($this->validResponse())->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://idp.test/metadata');
    }

    // --- Hilfen ------------------------------------------------------------------

    private function startLogin(): void
    {
        $this->get(route('auth.sso.redirect', $this->provider))->assertRedirect();
    }

    /**
     * Startet den Login (Session: State + Request-ID) und baut eine passende Antwort.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: string}
     */
    private function validResponse(array $options = []): array
    {
        $this->startLogin();

        $saml = $this->idp->response(
            route('auth.saml.acs', 'firma-saml'),
            $this->provider->spEntityId(),
            ['inResponseTo' => (string) session('saml2_request_id'), ...$options],
        );

        return [$saml, (string) session('state')];
    }

    /**
     * Stufe 1 (POST ohne Session) und Stufe 2 (GET mit Session) wie im Browser.
     *
     * @param  array{0: string, 1: string}  $response
     */
    private function postAcs(array $response): TestResponse
    {
        [$saml, $relay] = $response;

        $handover = $this->post(route('auth.saml.acs', $this->provider), ['SAMLResponse' => $saml, 'RelayState' => $relay]);
        $handover->assertStatus(303);

        return $this->get((string) $handover->headers->get('Location'));
    }
}
