<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Auth\RoleSimulation;
use App\Auth\SaftAppAuthentication;
use App\Filament\Actions\ChangePasswordAction;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MySettingsPage;
use App\Filament\Pages\RoleSimulationPage;
use App\Filament\Support\PanelAppearance;
use App\Help\HelpAssistant;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SimulateRole;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use App\Plugins\PluginRegistry;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // Routen immer registriert; Verfügbarkeit steuern die Seiten selbst (AuthSettings).
            ->passwordReset(RequestPasswordReset::class, ResetPassword::class)
            // Zwei-Faktor-Anmeldung mit Authenticator-App (B-057). Filament fragt bei der Anmeldung nach dem Code, wenn
            // die Person ihn eingerichtet hat; wer ihn einrichten muss, entscheidet RequireTwoFactor je Anfrage.
            // Kontoname in der App: ohne brandName() der Name aus den App-Einstellungen (beim Einrichten gelesen).
            ->multiFactorAuthentication([SaftAppAuthentication::make()->recoverable()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireTwoFactor::class)
            ->brandName(fn (): string => (string) config('app.name'))
            // Farbschema aus den App-Einstellungen (für alle).
            ->colors(fn (): array => PanelAppearance::colors())
            ->spa()
            // SSO-Anmeldung und -Abmeldung leiten zum Identity Provider weiter (andere Domain): echter Seitenwechsel,
            // sonst lädt der SPA-Modus die Weiterleitung per JavaScript nach und der Browser blockiert sie.
            ->spaUrlExceptions(fn (): array => [url('/auth/sso/*')])
            // Volle Fensterbreite statt Filaments Vorgabe von 80rem, damit breite Tabellen nicht unnötig scrollen.
            ->maxContentWidth(Width::Full)
            // Seitenleiste so breit wie der längste Menüeintrag der aktuellen Sprache, begrenzt (siehe STYLES_AFTER).
            ->sidebarWidth('max-content')
            // Komplett ein- und ausklappbar; Filament merkt sich den Zustand im Browser.
            ->sidebarFullyCollapsibleOnDesktop()
            ->databaseNotifications()
            // Eigene Stile und der persönliche Kontrastmodus (siehe PanelAppearance).
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => PanelAppearance::styles().PanelAppearance::themeScript())
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => PanelAppearance::headScript())
            // Hilfe-Assistent (Knopf „?“), nur wenn eingerichtet, eingeschaltet und mit Recht.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => app(HelpAssistant::class)->availableFor(($user = auth()->user()) instanceof User ? $user : null) ? view('filament.help-assistant-button')->render() : '')
            // „Ansicht als Rolle“: Hinweisband mit „Beenden“ auf jeder Seite.
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => ($roles = app(RoleSimulation::class)->active()) === null ? '' : view('filament.role-simulation-banner', ['roles' => $roles])->render())
            ->userMenuItems([
                Action::make('mySettings')
                    ->label(fn (): string => __('my_settings.title'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->url(fn (): string => MySettingsPage::getUrl()),
                ChangePasswordAction::make(),
                Action::make('roleSimulation')
                    ->label(fn (): string => __('role_simulation.menu'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (): string => RoleSimulationPage::getUrl())
                    ->visible(fn (): bool => RoleSimulationPage::canAccess()),
            ])
            ->errorNotifications()
            ->registerErrorNotification(fn (): string => __('errors.titles.default'), fn (): string => __('errors.http.500'))
            ->registerErrorNotification(fn (): string => __('errors.titles.403'), fn (): string => __('errors.http.403'), 403)
            ->registerErrorNotification(fn (): string => __('errors.titles.404'), fn (): string => __('errors.http.404'), 404)
            ->registerErrorNotification(fn (): string => __('errors.titles.419'), fn (): string => __('errors.http.419'), 419)
            ->registerErrorNotification(fn (): string => __('errors.titles.429'), fn (): string => __('errors.http.429'), 429)
            ->registerErrorNotification(fn (): string => __('errors.titles.500'), fn (): string => __('errors.http.500'), 500)
            ->registerErrorNotification(fn (): string => __('errors.titles.503'), fn (): string => __('errors.http.503'), 503)
            ->navigation(static fn (NavigationBuilder $builder): NavigationBuilder => app(MenuNavigationBuilder::class)
                ->build($builder, ($user = auth()->user()) instanceof User ? $user : null))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Installierte Plugins (Composer-Pakete, siehe Entwicklerhandbuch).
            ->plugins(array_values(app(PluginRegistry::class)->all()))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                SetLocale::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Auch für Livewire-Aktualisierungen: sonst gälten dort wieder die Super-Admin-Rechte.
            ->authMiddleware([SimulateRole::class], isPersistent: true);
    }
}
