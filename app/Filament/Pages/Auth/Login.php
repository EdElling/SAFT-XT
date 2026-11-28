<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Auth\AuthConfiguration;
use App\Auth\IdleTimeout;
use App\Models\IdentityProvider;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * Login-Seite abhängig vom Authentifizierungsmodus: Passwort-Formular, SSO-Buttons
 * oder beides; Notfall-Login für Super-Admins über ?local=1.
 */
class Login extends BaseLogin
{
    #[Locked]
    public bool $breakGlass = false;

    public function mount(): void
    {
        parent::mount();

        $this->breakGlass = request()->boolean('local') && $this->auth()->breakGlassAvailable();

        // Nach dem Abmelden wegen Inaktivität bzw. abgelaufenem „Angemeldet bleiben“ (B-162, LogoutWhenIdle).
        $expired = request()->query(IdleTimeout::LOGIN_QUERY);

        if ($expired === 'idle' || $expired === 'remember') {
            Notification::make()
                ->title(__('auth_admin.idle.'.$expired.'_title'))
                ->body($expired === 'idle' ? __('auth_admin.idle.idle_body', ['duration' => $this->idle()->limitLabel()]) : null)
                ->warning()
                ->persistent()
                ->send();
        }

        if (session()->has('sso_info')) {
            Notification::make()->title((string) session('sso_info'))->success()->send();
        }

        if (session()->has('sso_error')) {
            Notification::make()
                ->title(__('auth_admin.login.sso_failed'))
                ->body((string) session('sso_error'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * „Angemeldet bleiben“ gilt höchstens die eingestellte Dauer (B-162): so lange lebt auch das Remember-Cookie.
     * Massgeblich bleibt die Prüfung in LogoutWhenIdle.
     */
    #[Exposed]
    public function authenticate(): ?LoginResponse
    {
        $guard = Filament::auth();

        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration($this->idle()->rememberMinutes());
        }

        return parent::authenticate();
    }

    /**
     * Wird von Filament timing-sicher vor dem Login geprüft: abgelehnte Passwort-Logins
     * sind von falschen Zugangsdaten nicht unterscheidbar.
     */
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return parent::isUserAllowedToAccessPanel($user)
            && $user instanceof User
            && $this->auth()->mayUsePassword($user, $this->breakGlass);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [...parent::getCredentialsFromFormData($data), 'email' => User::normalizeEmail((string) $data['email'])];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getSsoComponent(),
                Text::make(__('auth_admin.login.sso_divider'))
                    ->visible(fn (): bool => $this->showsPasswordForm() && $this->ssoProviders() !== []),
                Text::make(__('auth_admin.login.break_glass_hint'))
                    ->visible(fn (): bool => $this->breakGlass)
                    ->color('warning'),
                $this->getFormContentComponent(),
                $this->getMultiFactorChallengeFormContentComponent(),
                Text::make(__('auth_admin.login.no_method'))
                    ->visible(fn (): bool => ! $this->showsPasswordForm() && $this->ssoProviders() === [])
                    ->color('danger'),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()
            ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication) && $this->showsPasswordForm());
    }

    protected function getPasswordFormComponent(): Component
    {
        if ($this->auth()->passwordResetEnabled()) {
            return parent::getPasswordFormComponent();
        }

        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/login.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required();
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->visible(fn (): bool => $this->idle()->rememberEnabled());
    }

    public function showsPasswordForm(): bool
    {
        return $this->auth()->allowsLocalLogin() || $this->breakGlass;
    }

    private function getSsoComponent(): Component
    {
        return Actions::make(fn (): array => array_map(
            fn (IdentityProvider $provider): Action => Action::make('sso'.$provider->id)
                ->label(__('auth_admin.login.sso_with', ['name' => $provider->name]))
                ->url(route('auth.sso.redirect', $provider))
                ->color('gray')
                ->outlined(),
            $this->ssoProviders(),
        ))
            ->fullWidth()
            ->visible(fn (): bool => $this->ssoProviders() !== [] && ! $this->breakGlass);
    }

    /**
     * @return list<IdentityProvider>
     */
    private function ssoProviders(): array
    {
        return once(fn (): array => $this->auth()->loginProviders()->values()->all());
    }

    private function idle(): IdleTimeout
    {
        return app(IdleTimeout::class);
    }

    private function auth(): AuthConfiguration
    {
        return app(AuthConfiguration::class);
    }
}
