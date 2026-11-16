<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Auth\Sso\Saml\Certificates;
use App\Filament\Pages\Auth\Login;
use App\Filament\Widgets\AccountChangesWidget;
use App\Filament\Widgets\IdentityProviderStatusWidget;
use App\Filament\Widgets\LastLoginWidget;
use App\Filament\Widgets\LoginActivityWidget;
use App\Filament\Widgets\MyChangesWidget;
use App\Filament\Widgets\MyLoginsWidget;
use App\Filament\Widgets\RecentChangesWidget;
use App\Filament\Widgets\UsersOverviewWidget;
use App\Models\IdentityProvider;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const array PERSONAL = [LastLoginWidget::class, MyLoginsWidget::class, MyChangesWidget::class, AccountChangesWidget::class];

    private const array ADMIN = [RecentChangesWidget::class, IdentityProviderStatusWidget::class, LoginActivityWidget::class, UsersOverviewWidget::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    // --- Sichtbarkeit ------------------------------------------------------------------

    public function test_default_role_sees_personal_widgets_only(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(User::ROLE_DEFAULT));

        foreach (self::PERSONAL as $widget) {
            $this->assertTrue($widget::canView(), "{$widget} sollte sichtbar sein");
        }

        foreach (self::ADMIN as $widget) {
            $this->assertFalse($widget::canView(), "{$widget} darf nicht sichtbar sein");
        }
    }

    public function test_super_admin_sees_all_widgets(): void
    {
        $this->actingAs($this->superAdmin());

        foreach ([...self::PERSONAL, ...self::ADMIN] as $widget) {
            $this->assertTrue($widget::canView(), $widget);
        }
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function dataPermissions(): array
    {
        return [
            'systemweite Änderungen' => [RecentChangesWidget::class, 'widgets.recent_changes', 'activity_log.view'],
            'IdP-Status' => [IdentityProviderStatusWidget::class, 'widgets.idp_status', 'identity_providers.view'],
            'Anmeldeaktivität' => [LoginActivityWidget::class, 'widgets.login_activity', 'users.view'],
            'Benutzerübersicht' => [UsersOverviewWidget::class, 'widgets.users_overview', 'users.view'],
        ];
    }

    /**
     * @param  class-string  $widget
     */
    #[DataProvider('dataPermissions')]
    public function test_admin_widgets_also_require_data_permission(string $widget, string $widgetPermission, string $dataPermission): void
    {
        $this->actingAs($this->userWithPermissions([$widgetPermission]));
        $this->assertFalse($widget::canView(), 'Widget-Permission allein darf keine fremden Daten freigeben.');

        $this->actingAs($this->userWithPermissions([$widgetPermission, $dataPermission]));
        $this->assertTrue($widget::canView());
    }

    public function test_hidden_widget_cannot_be_loaded_directly(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(User::ROLE_DEFAULT));

        Livewire::test(RecentChangesWidget::class)->assertForbidden();
    }

    public function test_widget_assignment_via_role_permissions(): void
    {
        $role = Role::create(['name' => 'leitung']);
        $role->givePermissionTo(['widgets.users_overview', 'users.view']);
        $this->actingAs(User::factory()->create()->assignRole($role));

        $this->assertTrue(UsersOverviewWidget::canView());
        $this->assertFalse(LastLoginWidget::canView(), 'Persönliche Widgets nur, wenn der Rolle zugewiesen.');
    }

    public function test_reseeding_keeps_admin_changes_to_default_role(): void
    {
        $default = Role::findByName(User::ROLE_DEFAULT);
        $default->revokePermissionTo('widgets.my_logins');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse($default->fresh()->hasPermissionTo('widgets.my_logins'));
    }

    public function test_dashboard_renders_for_normal_user(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(User::ROLE_DEFAULT))
            ->get('/admin')
            ->assertOk();
    }

    // --- Persönliche Widgets -----------------------------------------------------------

    public function test_last_login_shows_previous_login_and_failed_attempts(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345'])->assignRole(User::ROLE_DEFAULT);
        $this->login($user, 'Geheim-12345');
        $this->travel(2)->hours();
        $this->login($user, 'falsch');
        $this->login($user, 'Geheim-12345');

        $this->actingAs($user);
        Livewire::test(LastLoginWidget::class)
            ->assertSee('Passwort')
            ->assertSee('vor 2 Stunden')
            ->assertSee('Mit Ihrer E-Mail-Adresse wurde ein falsches Passwort eingegeben.');
    }

    public function test_failed_login_never_stores_password(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345']);
        $this->login($user, 'mein-falsches-passwort');

        $failed = Activity::query()->where('event', 'login_failed')->firstOrFail();
        $this->assertSame($user->email, $failed->properties['email']);
        $this->assertStringNotContainsString('mein-falsches-passwort', Activity::query()->get()->toJson());
    }

    public function test_personal_widgets_never_show_foreign_data(): void
    {
        $me = User::factory()->create(['name' => 'Ich Selbst'])->assignRole(User::ROLE_DEFAULT);
        $other = User::factory()->create(['name' => 'Fremde Person']);

        $this->actingAs($other);
        Menu::create(['label' => 'Fremder Menüpunkt']);

        $this->actingAs($me);
        Menu::create(['label' => 'Mein Menüpunkt']);

        Livewire::test(MyChangesWidget::class)
            ->assertSee('Mein Menüpunkt')
            ->assertDontSee('Fremder Menüpunkt');
    }

    public function test_account_changes_show_role_changes_by_others(): void
    {
        $me = User::factory()->create()->assignRole(User::ROLE_DEFAULT);
        $admin = $this->superAdmin();

        $this->actingAs($admin);
        $me->update(['name' => 'Neuer Name']);

        $this->actingAs($me);
        Livewire::test(AccountChangesWidget::class)
            ->assertSee($admin->name)
            ->assertSee('Neuer Name');
    }

    // --- Admin-Widgets -----------------------------------------------------------------

    public function test_idp_status_warns_about_expiring_certificate(): void
    {
        $this->actingAs($this->superAdmin());
        IdentityProvider::create([
            'type' => 'saml', 'name' => 'Bald ablaufend', 'slug' => 'bald',
            'settings' => ['idp_certificate' => Certificates::generateSelfSigned('idp', validDays: 10)['certificate']],
        ]);

        Livewire::test(IdentityProviderStatusWidget::class)
            ->assertSee('Bald ablaufend')
            ->assertSee('in 9 Tagen')
            ->assertSee('Anmeldemodus: Lokal');
    }

    public function test_login_activity_counts_logins_by_method_and_failures(): void
    {
        $user = User::factory()->create(['password' => 'Geheim-12345']);
        $this->login($user, 'Geheim-12345');
        $this->login($user, 'falsch');

        $this->actingAs($this->superAdmin());
        Livewire::test(LoginActivityWidget::class)
            ->assertSee('Passwort: 1 · SSO: 0')
            ->assertSee('Fehlgeschlagene Anmeldungen (24 h)');
    }

    public function test_users_overview_links_to_filtered_user_list(): void
    {
        User::factory()->create();
        $this->actingAs($this->superAdmin());

        Livewire::test(UsersOverviewWidget::class)
            ->assertSee('Ohne Rolle')
            ->assertSee('filters', escape: false);

        $this->get('/admin/users?filters[without_roles][isActive]=1')->assertOk();
    }

    public function test_all_widgets_render_with_data_for_super_admin(): void
    {
        $admin = User::factory()->create(['password' => 'Geheim-12345'])->assignRole(User::ROLE_SUPER_ADMIN);
        $this->login($admin, 'Geheim-12345');
        $this->login($admin, 'Geheim-12345');
        $this->actingAs($admin);
        Menu::create(['label' => 'Testeintrag']);
        IdentityProvider::create(['type' => 'entra', 'name' => 'Entra-Test', 'slug' => 'entra-test', 'settings' => ['tenant_id' => 'x.onmicrosoft.com']]);

        Livewire::test(MyLoginsWidget::class)->assertSee('Anmeldung')->assertSee('Passwort');
        Livewire::test(RecentChangesWidget::class)->assertSee('Menu #');
        Livewire::test(IdentityProviderStatusWidget::class)->assertSee('Client-Secret-Ablauf in Entra prüfen');
        Livewire::test(UsersOverviewWidget::class)->assertSee('Mit SSO-Verknüpfung');
        Livewire::test(MyChangesWidget::class)->assertSee('Testeintrag');
    }

    private function login(User $user, string $password): void
    {
        auth()->logout();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => $password])
            ->call('authenticate');
    }
}
