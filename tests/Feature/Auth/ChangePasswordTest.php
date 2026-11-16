<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\AuthMode;
use App\Filament\Actions\ChangePasswordAction;
use App\Models\SocialAccount;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfiguresAuth;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** „Passwort ändern“ im Benutzermenü. */
class ChangePasswordTest extends TestCase
{
    use ConfiguresAuth;
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->setAuthMode(AuthMode::Hybrid);
        Filament::setCurrentPanel('admin');
    }

    public function test_user_changes_own_password(): void
    {
        $user = $this->userWithPermissions();
        $user->forceFill(['password' => 'Alt-Passwort-123'])->save();
        $this->actingAs($user);

        $this->assertTrue(ChangePasswordAction::available($user), 'Vorbedingung');
        // Je Versuch eine neue Komponente: Nach einem Fehler bleibt der Dialog sonst offen.
        $menu = fn () => Livewire::test(Topbar::class);

        $menu()->callAction('changePassword', ['current_password' => 'falsch', 'password' => 'Neu-Passwort-456', 'password_confirmation' => 'Neu-Passwort-456'])
            ->assertHasErrors(['mountedActions.0.data.current_password']);
        $menu()->callAction('changePassword', ['current_password' => 'Alt-Passwort-123', 'password' => 'Neu-Passwort-456', 'password_confirmation' => 'anders'])
            ->assertHasErrors(['mountedActions.0.data.password']);
        $menu()->callAction('changePassword', ['current_password' => 'Alt-Passwort-123', 'password' => 'Neu-Passwort-456', 'password_confirmation' => 'Neu-Passwort-456'])
            ->assertHasNoErrors()
            ->assertNotified('Passwort geändert');

        $this->assertTrue(Hash::check('Neu-Passwort-456', $user->fresh()->password));
        $this->assertTrue(Activity::query()->where('event', 'password_changed')->where('causer_id', $user->id)->exists());
    }

    public function test_not_offered_with_active_sso_link_or_without_local_login(): void
    {
        $user = $this->userWithPermissions();
        $this->assertTrue(ChangePasswordAction::available($user));

        $provider = $this->entraProvider();
        SocialAccount::create(['user_id' => $user->id, 'identity_provider_id' => $provider->id, 'provider' => 'entra', 'provider_user_id' => 'oid-1']);
        $this->assertFalse(ChangePasswordAction::available($user), 'Aktive SSO-Verbindung.');

        $provider->update(['is_enabled' => false]);
        $this->assertTrue(ChangePasswordAction::available($user), 'Identity Provider abgeschaltet: lokales Passwort zählt wieder.');

        $this->setAuthMode(AuthMode::Sso);
        $this->assertFalse(ChangePasswordAction::available($user), 'Nur SSO.');

        $this->setAuthMode(AuthMode::Hybrid);
        $user->forceFill(['password' => null])->save();
        $this->assertFalse(ChangePasswordAction::available($user->fresh()), 'Konto ohne Passwort.');

        $this->actingAs($user);
        Livewire::test(Topbar::class)->assertActionDoesNotExist('changePassword');
    }
}
