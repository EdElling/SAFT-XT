<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Auth\Sso\SsoIdentity;
use App\Auth\Sso\SsoUserResolver;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\AuthSettingsPage;
use App\Filament\Resources\IdentityProviders\Pages\EditIdentityProvider;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Settings\AuthSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Navigation\NavigationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class RoleMappingTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    private IdentityProvider $provider;

    private Role $sachbearbeitung;

    private Role $leitung;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->setAuthMode(AuthMode::Hybrid);

        $this->sachbearbeitung = Role::create(['name' => 'sachbearbeitung']);
        $this->leitung = Role::create(['name' => 'leitung']);

        $this->provider = $this->markTested($this->entraProvider([
            'settings' => [
                'tenant_id' => 'contoso.onmicrosoft.com',
                'client_id' => '11111111-2222-3333-4444-555555555555',
                'role_sync' => true,
                'role_claim' => 'roles',
            ],
        ]));
        $this->provider->roleMappings()->createMany([
            ['external_value' => 'SAFT.Sachbearbeitung', 'role_id' => $this->sachbearbeitung->id],
            ['external_value' => 'SAFT.Leitung', 'role_id' => $this->leitung->id],
        ]);
    }

    // --- Standardrolle -----------------------------------------------------------------

    public function test_default_role_exists_without_admin_permissions(): void
    {
        $role = Role::findByName(User::ROLE_DEFAULT);
        $this->assertEqualsCanonicalizing(
            array_map(static fn (string $w): string => "widgets.{$w}", RolesAndPermissionsSeeder::PERSONAL_WIDGETS),
            $role->permissions->pluck('name')->all(),
            'Standardrolle: nur persönliche Dashboard-Widgets, keine Admin-Rechte.',
        );

        $user = User::factory()->create()->assignRole($role);
        $nav = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user)->getNavigation();
        $labels = collect($nav)->flatMap(fn ($g) => collect($g->getItems())->map->getLabel())->all();

        $this->assertSame(['Dashboard', 'Dokumentation'], $labels);
        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
    }

    public function test_default_role_is_preselected_for_new_users(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Neu', 'email' => 'neu@firma.de', 'password' => 'Geheim-12345', 'password_confirmation' => 'Geheim-12345'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(User::where('email', 'neu@firma.de')->firstOrFail()->hasRole(User::ROLE_DEFAULT));
    }

    public function test_default_role_cannot_be_deleted_or_renamed_until_setting_changes(): void
    {
        $role = Role::findByName(User::ROLE_DEFAULT);

        foreach ([fn () => $role->delete(), fn () => $role->update(['name' => 'anders'])] as $action) {
            try {
                $action();
                $this->fail('BusinessRuleException erwartet');
            } catch (BusinessRuleException $e) {
                $this->assertSame('role.default_protected', $e->errorCode);
            }
        }

        $settings = app(AuthSettings::class);
        $settings->default_role = 'leitung';
        $settings->save();

        $role->refresh()->delete();
        $this->assertModelMissing($role);
    }

    public function test_default_role_setting_is_saved_and_audited(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(AuthSettingsPage::class)
            ->assertFormFieldExists('default_role', fn ($field): bool => ! array_key_exists('super-admin', $field->getOptions()))
            ->fillForm(['default_role' => 'sachbearbeitung'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sachbearbeitung', app(AuthSettings::class)->refresh()->default_role);
        $log = Activity::query()->where('log_name', 'settings.auth')->latest('id')->firstOrFail();
        $this->assertSame(['default_role' => 'sachbearbeitung'], $log->attribute_changes['attributes']);
    }

    public function test_auto_provisioning_falls_back_to_global_default_role(): void
    {
        $this->provider->update(['auto_provision' => true, 'settings' => [...$this->provider->settings, 'role_sync' => false]]);

        $user = $this->resolve(new SsoIdentity('oid-new', 'neu@firma.de', 'Neu'));

        $this->assertSame([User::ROLE_DEFAULT], $user->roles->pluck('name')->all());
    }

    // --- Rollen-Mapping ----------------------------------------------------------------

    public function test_matching_roles_are_granted_and_unmanaged_roles_kept(): void
    {
        $user = User::factory()->create(['email' => 'max@firma.de'])->assignRole(User::ROLE_DEFAULT);

        $this->resolve(new SsoIdentity('oid-1', 'max@firma.de', 'Max', roleValues: ['saft.sachbearbeitung', 'Fremde.Rolle']));

        $this->assertEqualsCanonicalizing([User::ROLE_DEFAULT, 'sachbearbeitung'], $user->fresh()->roles->pluck('name')->all());
    }

    public function test_managed_roles_are_revoked_when_no_longer_matching(): void
    {
        $user = User::factory()->create(['email' => 'max@firma.de'])->assignRole(['leitung', User::ROLE_DEFAULT]);

        $this->resolve(new SsoIdentity('oid-1', 'max@firma.de', 'Max', roleValues: ['SAFT.Sachbearbeitung']));

        $this->assertEqualsCanonicalizing([User::ROLE_DEFAULT, 'sachbearbeitung'], $user->fresh()->roles->pluck('name')->all());

        $log = Activity::query()->where('subject_type', User::class)->where('subject_id', $user->id)->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['leitung', User::ROLE_DEFAULT], $log->attribute_changes['old']['roles']);
        $this->assertEqualsCanonicalizing(['sachbearbeitung', User::ROLE_DEFAULT], $log->attribute_changes['attributes']['roles']);
    }

    public function test_unavailable_values_leave_roles_untouched(): void
    {
        $user = User::factory()->create(['email' => 'max@firma.de'])->assignRole('leitung');

        $this->resolve(new SsoIdentity('oid-1', 'max@firma.de', 'Max', roleValues: null));

        $this->assertSame(['leitung'], $user->fresh()->roles->pluck('name')->all());
    }

    public function test_disabled_sync_leaves_roles_untouched(): void
    {
        $this->provider->update(['settings' => [...$this->provider->settings, 'role_sync' => false]]);
        $user = User::factory()->create(['email' => 'max@firma.de'])->assignRole('leitung');

        $this->resolve(new SsoIdentity('oid-1', 'max@firma.de', 'Max', roleValues: []));

        $this->assertSame(['leitung'], $user->fresh()->roles->pluck('name')->all());
    }

    public function test_super_admin_can_never_be_granted_by_mapping(): void
    {
        // Selbst wenn eine Zuordnung direkt in der DB stünde.
        $this->provider->roleMappings()->create(['external_value' => 'Admins', 'role_id' => Role::findByName(User::ROLE_SUPER_ADMIN)->id]);
        $user = User::factory()->create(['email' => 'max@firma.de']);

        $this->resolve(new SsoIdentity('oid-1', 'max@firma.de', 'Max', roleValues: ['Admins']));

        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    public function test_require_role_match_rejects_without_creating_account(): void
    {
        $this->provider->update([
            'auto_provision' => true,
            'settings' => [...$this->provider->settings, 'require_role_match' => true],
        ]);

        try {
            $this->resolve(new SsoIdentity('oid-x', 'fremd@firma.de', 'Fremd', roleValues: ['Andere.Gruppe']));
            $this->fail('Ablehnung erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertSame('sso.no_role_match', $e->errorCode);
        }

        $this->assertDatabaseMissing('users', ['email' => 'fremd@firma.de']);
    }

    // --- Formular ----------------------------------------------------------------------

    public function test_mappings_are_saved_via_form_and_super_admin_is_not_selectable(): void
    {
        $this->actingAs($this->superAdmin());

        $page = Livewire::test(EditIdentityProvider::class, ['record' => $this->provider->getRouteKey()]);

        $page->assertFormFieldExists('roleMappings', function ($repeater): bool {
            $item = array_values($repeater->getItems())[0] ?? null;
            $select = $item?->getComponent('role_id');

            return $select !== null && ! in_array(User::ROLE_SUPER_ADMIN, $select->getOptions(), true);
        });

        $page->fillForm(['roleMappings' => [
            ['external_value' => 'SAFT.Leitung', 'role_id' => $this->leitung->id],
            ['external_value' => 'Neue.Rolle', 'role_id' => $this->sachbearbeitung->id],
        ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            ['SAFT.Leitung', 'Neue.Rolle'],
            $this->provider->roleMappings()->pluck('external_value')->all(),
        );
        $this->assertTrue($this->provider->refresh()->last_test_successful, 'Mapping-Änderungen erfordern keinen neuen Verbindungstest.');
    }

    public function test_duplicate_mappings_are_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(EditIdentityProvider::class, ['record' => $this->provider->getRouteKey()])
            ->fillForm(['roleMappings' => [
                ['external_value' => 'Gruppe', 'role_id' => $this->leitung->id],
                ['external_value' => ' gruppe ', 'role_id' => $this->leitung->id],
            ]])
            ->call('save')
            ->assertHasFormErrors(['roleMappings']);
    }

    private function resolve(SsoIdentity $identity): User
    {
        return app(SsoUserResolver::class)->resolve($this->provider->refresh(), $identity);
    }
}
