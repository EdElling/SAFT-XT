<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\IdleTimeout;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\AuthSettingsPage;
use App\Models\User;
use App\Settings\AuthSettings;
use Filament\Notifications\Notification;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * Abmelden nach Inaktivität (B-162): nur Bedienung zählt, „Angemeldet bleiben“ mit Leerlauf-Grenze und Höchstdauer.
 */
class IdleTimeoutTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    public function test_password_login_starts_the_idle_clock_and_limits_remember_me(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => 'Geheim-12345']);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'Geheim-12345', 'remember' => true])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertSame(now()->getTimestamp(), session(IdleTimeout::SESSION_KEY));
        $recaller = Cookie::queued($this->guard()->getRecallerName());
        $this->assertNotNull($recaller);
        $this->assertSame(now()->addHours(12)->getTimestamp(), $recaller->getExpiresTime());
    }

    public function test_request_after_the_limit_logs_out_with_notice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertOk();

        $this->travel(241)->minutes();

        $this->get('/admin')->assertRedirect('/admin/login?expired=idle');
        $this->assertGuest();
        $logout = Activity::query()->where('event', 'logout')->where('causer_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('idle', $logout->properties['method']);

        Livewire::withQueryParams([IdleTimeout::LOGIN_QUERY => 'idle'])->test(Login::class)
            ->assertNotified(Notification::make()
                ->title('Sie wurden wegen Inaktivität abgemeldet.')
                ->body('Die Anwendung wurde 4 Stunden lang nicht bedient. Bitte melden Sie sich erneut an.')
                ->warning()
                ->persistent());
    }

    public function test_request_within_the_limit_counts_as_activity(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk();

        $this->travel(200)->minutes();
        $this->get('/admin')->assertOk();
        $this->travel(200)->minutes();

        $this->get('/admin')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_livewire_request_counts_only_when_marked_as_activity(): void
    {
        $this->freezeTime();
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk();
        $started = now()->getTimestamp();

        $this->travel(10)->minutes();
        $this->withHeader('X-Livewire', '1')->get('/admin')->assertOk();
        $this->assertSame($started, session(IdleTimeout::SESSION_KEY));

        $this->withHeaders(['X-Livewire' => '1', IdleTimeout::HEADER => '1'])->get('/admin')->assertOk();
        $this->assertSame(now()->getTimestamp(), session(IdleTimeout::SESSION_KEY));
    }

    public function test_status_does_not_count_but_reported_activity_does(): void
    {
        $this->freezeTime();
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk();

        $this->travel(60)->minutes();
        $this->getJson(route('session.idle'))->assertOk()->assertExactJson(['remaining' => 180 * 60]);
        $this->getJson(route('session.idle'))->assertExactJson(['remaining' => 180 * 60]);

        $this->postJson(route('session.activity'))->assertOk()->assertExactJson(['remaining' => 240 * 60]);
    }

    public function test_status_after_the_limit_answers_401(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk();
        $this->travel(5)->hours();

        $this->getJson(route('session.idle'))->assertUnauthorized()->assertJsonPath('redirect', url('/admin/login?expired=idle'));
        $this->assertGuest();
    }

    public function test_without_limit_nobody_is_logged_out(): void
    {
        $this->updateSettings(['idle_timeout_minutes' => null]);
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk();

        $this->travel(30)->hours();

        $this->get('/admin')->assertOk()->assertDontSee('saft-idle-warning');
        $this->getJson(route('session.idle'))->assertExactJson(['remaining' => null]);
    }

    public function test_page_contains_the_warning_with_the_remaining_time(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()
            ->assertSee('data-remaining="'.(240 * 60).'"', escape: false)
            ->assertSee('saft-idle-warning');
    }

    public function test_remember_me_within_both_limits_signs_in_again(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->remembered($user, loginHoursAgo: 11, activityMinutesAgo: 200);

        // Ohne Bedienung (Poll): Die Uhr läuft ab der letzten Bedienung aus dem Cookie weiter.
        $this->withHeader('X-Livewire', '1')->get('/admin')->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(now()->subMinutes(200)->getTimestamp(), session(IdleTimeout::SESSION_KEY));
    }

    public function test_remember_me_after_the_idle_limit_is_rejected(): void
    {
        $this->freezeTime();
        $this->remembered(User::factory()->create(), loginHoursAgo: 6, activityMinutesAgo: 241);

        $this->get('/admin')->assertRedirect('/admin/login?expired=idle');
        $this->assertGuest();
    }

    public function test_remember_me_after_the_maximum_duration_is_rejected(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->remembered($user, loginHoursAgo: 13, activityMinutesAgo: 5);

        $this->get('/admin')->assertRedirect('/admin/login?expired=remember');
        $this->assertGuest();
        $logout = Activity::query()->where('event', 'logout')->where('causer_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('remember_expired', $logout->properties['method']);
    }

    public function test_remember_me_without_activity_cookie_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->setRememberToken('token-123');
        $user->save();
        $this->withCookie($this->guard()->getRecallerName(), $this->recallerValue($user));

        $this->get('/admin')->assertRedirect('/admin/login?expired=remember');
        $this->assertGuest();
    }

    public function test_disabled_remember_me_is_not_offered_and_not_accepted(): void
    {
        $this->updateSettings(['remember_me_enabled' => false]);

        $this->get('/admin/login')->assertOk()->assertDontSee('Angemeldet bleiben');

        $this->freezeTime();
        $this->remembered(User::factory()->create(), loginHoursAgo: 1, activityMinutesAgo: 5);
        $this->get('/admin')->assertRedirect('/admin/login?expired=remember');
        $this->assertGuest();
    }

    public function test_settings_are_saved(): void
    {
        $this->actingAs($this->userWithPermissions(['settings.auth.view', 'settings.auth.update']));

        Livewire::test(AuthSettingsPage::class)
            ->fillForm(['idle_timeout_minutes' => 60, 'remember_me_enabled' => true, 'remember_me_hours' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(AuthSettings::class)->refresh();
        $this->assertSame(60, $settings->idle_timeout_minutes);
        $this->assertSame(8, $settings->remember_me_hours);

        Livewire::test(AuthSettingsPage::class)
            ->fillForm(['idle_timeout_minutes' => null, 'remember_me_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings->refresh();
        $this->assertNull($settings->idle_timeout_minutes);
        $this->assertFalse($settings->remember_me_enabled);
        $this->assertSame(8, $settings->remember_me_hours);
    }

    /** Neuer Browser mit Remember-Cookie und Bedienungs-Cookie von früher. */
    private function remembered(User $user, int $loginHoursAgo, int $activityMinutesAgo): void
    {
        $user->setRememberToken('token-123');
        $user->save();

        $this->withCookie($this->guard()->getRecallerName(), $this->recallerValue($user))
            ->withCookie(IdleTimeout::COOKIE, (string) json_encode([
                'a' => now()->subMinutes($activityMinutesAgo)->getTimestamp(),
                'l' => now()->subHours($loginHoursAgo)->getTimestamp(),
            ]));
    }

    private function recallerValue(User $user): string
    {
        return $user->id.'|'.$user->getRememberToken().'|'.$this->guard()->hashPasswordForCookie($user->getAuthPassword());
    }

    private function guard(): SessionGuard
    {
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        return $guard;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateSettings(array $values): void
    {
        $settings = app(AuthSettings::class);

        foreach ($values as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
