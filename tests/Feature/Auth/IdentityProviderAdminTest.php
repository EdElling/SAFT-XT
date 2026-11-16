<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Auth\Sso\Saml\Certificates;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\AuthSettingsPage;
use App\Filament\Resources\IdentityProviders\Pages\CreateIdentityProvider;
use App\Filament\Resources\IdentityProviders\Pages\EditIdentityProvider;
use App\Models\Concerns\AuditMask;
use App\Models\IdentityProvider;
use App\Settings\AuthSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class IdentityProviderAdminTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const array SAML_DEFAULTS = [
        'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'attribute_email' => 'email',
        'sign_requests' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->actingAs($this->superAdmin());
    }

    public function test_pages_require_permissions(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view']));
        $this->get('/admin/identity-providers')->assertForbidden();
        $this->get('/admin/auth-settings')->assertForbidden();

        $this->actingAs($this->userWithPermissions(['identity_providers.view', 'settings.auth.view']));
        $this->get('/admin/identity-providers')->assertOk();
        $this->get('/admin/auth-settings')->assertOk();
    }

    public function test_create_entra_provider_encrypts_secret(): void
    {
        Livewire::test(CreateIdentityProvider::class)
            ->fillForm([
                'type' => 'entra',
                'name' => 'Contoso',
                'slug' => 'contoso',
                'settings' => ['tenant_id' => 'contoso.onmicrosoft.com', 'client_id' => '11111111-2222-3333-4444-555555555555'],
                'new_secrets' => ['client_secret' => 'mein-geheimnis'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $provider = IdentityProvider::where('slug', 'contoso')->firstOrFail();
        $this->assertSame('mein-geheimnis', $provider->secret('client_secret'));

        $raw = (string) DB::table('identity_providers')->where('id', $provider->id)->value('secrets');
        $this->assertStringNotContainsString('mein-geheimnis', $raw);
    }

    public function test_secret_is_never_in_audit_log_but_change_is_recorded(): void
    {
        $provider = $this->entraProvider();
        $provider->update(['secrets' => ['client_secret' => 'neues-geheimnis']]);

        $logs = Activity::query()->where('subject_type', IdentityProvider::class)->where('subject_id', $provider->id)->get();
        $json = $logs->toJson();

        $this->assertStringNotContainsString('super-geheim', $json);
        $this->assertStringNotContainsString('neues-geheimnis', $json);
        $this->assertTrue($logs->contains(fn (Activity $a): bool => ($a->attribute_changes['attributes']['secrets'] ?? null) === AuditMask::VALUE && $a->event === 'updated'));
    }

    public function test_editing_without_secret_keeps_stored_secret(): void
    {
        $provider = $this->entraProvider();

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])
            ->fillForm(['name' => 'Umbenannt'])
            ->call('save')
            ->assertHasNoFormErrors();

        $provider->refresh();
        $this->assertSame('Umbenannt', $provider->name);
        $this->assertSame('super-geheim', $provider->secret('client_secret'));
    }

    public function test_configuration_change_invalidates_connection_test(): void
    {
        $provider = $this->markTested($this->entraProvider());

        $provider->update(['name' => 'Nur Name']);
        $this->assertTrue($provider->refresh()->last_test_successful);

        $provider->update(['settings' => [...$provider->settings, 'tenant_id' => 'anders.onmicrosoft.com']]);
        $this->assertNull($provider->refresh()->last_test_successful);
    }

    public function test_cannot_switch_to_sso_without_verified_provider(): void
    {
        $this->entraProvider();

        Livewire::test(AuthSettingsPage::class)
            ->fillForm(['mode' => 'sso'])
            ->call('save')
            ->assertHasFormErrors(['mode']);

        $this->assertSame(AuthMode::Local, app(AuthSettings::class)->refresh()->mode);
    }

    public function test_switching_mode_is_saved_and_audited(): void
    {
        $this->markTested($this->entraProvider());

        Livewire::test(AuthSettingsPage::class)
            ->fillForm(['mode' => 'sso'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AuthMode::Sso, app(AuthSettings::class)->refresh()->mode);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'settings.auth', 'event' => 'updated']);

        $log = Activity::query()->where('log_name', 'settings.auth')->latest('id')->firstOrFail();
        $this->assertSame(['mode' => 'sso'], $log->attribute_changes['attributes']);
        $this->assertSame(['mode' => 'local'], $log->attribute_changes['old']);
    }

    public function test_settings_page_is_read_only_without_update_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['settings.auth.view']));

        Livewire::test(AuthSettingsPage::class)
            ->fillForm(['mode' => 'hybrid'])
            ->call('save');

        $this->assertSame(AuthMode::Local, app(AuthSettings::class)->refresh()->mode);
    }

    public function test_last_active_provider_cannot_be_disabled_in_sso_mode(): void
    {
        $provider = $this->markTested($this->entraProvider());
        $this->setAuthMode(AuthMode::Sso);

        $this->expectException(BusinessRuleException::class);
        $provider->update(['is_enabled' => false]);
    }

    public function test_provider_can_be_disabled_when_another_is_active(): void
    {
        $provider = $this->markTested($this->entraProvider());
        $this->entraProvider(['slug' => 'zweiter', 'name' => 'Zweiter']);
        $this->setAuthMode(AuthMode::Sso);

        $provider->update(['is_enabled' => false]);

        $this->assertFalse($provider->refresh()->is_enabled);
    }

    public function test_entra_connection_test_success(): void
    {
        Http::fake([
            'login.microsoftonline.com/contoso.onmicrosoft.com/v2.0/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://login.microsoftonline.com/ABC00000-0000-0000-0000-000000000001/v2.0',
                'token_endpoint' => 'https://login.microsoftonline.com/abc/oauth2/v2.0/token',
            ]),
            'login.microsoftonline.com/abc/oauth2/v2.0/token' => Http::response(['access_token' => 'x']),
        ]);
        $provider = $this->entraProvider();

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])
            ->callAction('testConnection');

        $this->assertTrue($provider->refresh()->last_test_successful);
        $this->assertSame('abc00000-0000-0000-0000-000000000001', $provider->setting('tenant_guid'), 'Tenant-GUID aus dem Discovery-Issuer.');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/token') && $request['client_secret'] === 'super-geheim');
    }

    public function test_entra_connection_test_reports_invalid_secret(): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response(['issuer' => 'i', 'token_endpoint' => 'https://login.microsoftonline.com/abc/token']),
            '*/token' => Http::response(['error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: 123"], 401),
        ]);
        $provider = $this->entraProvider();

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])
            ->callAction('testConnection');

        $provider->refresh();
        $this->assertFalse($provider->last_test_successful);
        $this->assertStringContainsString('AADSTS7000215', (string) $provider->last_test_message);
        $this->assertStringNotContainsString('Trace ID', (string) $provider->last_test_message);
    }

    public function test_saml_connection_test_with_metadata_url(): void
    {
        $cert = Certificates::generateSelfSigned('idp.example.com')['certificate'];
        $body = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $cert));

        Http::fake(['idp.example.com/metadata' => Http::response(<<<XML
            <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="https://idp.example.com">
              <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
                <md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>{$body}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>
                <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example.com/sso"/>
              </md:IDPSSODescriptor>
            </md:EntityDescriptor>
            XML)]);

        $provider = IdentityProvider::create([
            'type' => 'saml',
            'name' => 'SAML-IdP',
            'slug' => 'saml',
            'settings' => ['idp_metadata_url' => 'https://idp.example.com/metadata', ...self::SAML_DEFAULTS],
        ]);

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])
            ->callAction('testConnection');

        $provider->refresh();
        $this->assertTrue($provider->last_test_successful, (string) $provider->last_test_message);
        $this->assertStringContainsString('https://idp.example.com', (string) $provider->last_test_message);
    }

    public function test_saml_detects_mismatched_sp_key_pair(): void
    {
        $a = Certificates::generateSelfSigned('a');
        $b = Certificates::generateSelfSigned('b');
        $idpCert = Certificates::generateSelfSigned('idp')['certificate'];

        $provider = IdentityProvider::create([
            'type' => 'saml',
            'name' => 'SAML',
            'slug' => 'saml',
            'settings' => [
                'idp_entity_id' => 'https://idp', 'idp_sso_url' => 'https://idp/sso', 'idp_certificate' => $idpCert,
                ...self::SAML_DEFAULTS, 'sign_requests' => true, 'sp_certificate' => $a['certificate'],
            ],
            'secrets' => ['sp_private_key' => $b['private_key']],
        ]);

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])->callAction('testConnection');

        $this->assertFalse($provider->refresh()->last_test_successful);
        $this->assertSame(__('auth_admin.test.saml_sp_key_mismatch'), $provider->last_test_message);
    }

    public function test_default_role_never_offers_super_admin(): void
    {
        $provider = $this->entraProvider(['auto_provision' => true]);

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])
            ->assertFormFieldExists('default_role_id', fn ($field): bool => ! in_array('super-admin', $field->getOptions(), true));
    }

    /** Ein leeres Feld bedeutet „globale Standardrolle“; der Platzhalter nennt sie. */
    public function test_empty_default_role_shows_the_global_default_role(): void
    {
        $provider = $this->entraProvider(['auto_provision' => true]);
        $this->setting('default_role', 'benutzer');

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])
            ->assertFormFieldExists('default_role_id', fn ($field): bool => $field->getPlaceholder() === __('idp.users.default_role_global', ['role' => 'benutzer']))
            ->assertSee(__('idp.users.default_role_help'));

        $this->setting('default_role', null);

        Livewire::test(EditIdentityProvider::class, ['record' => $provider->getRouteKey()])
            ->assertFormFieldExists('default_role_id', fn ($field): bool => $field->getPlaceholder() === __('idp.users.default_role_none'));
    }

    private function setting(string $name, ?string $value): void
    {
        $settings = app(AuthSettings::class);
        $settings->{$name} = $value;
        $settings->save();
    }
}
