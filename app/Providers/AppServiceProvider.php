<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\AnthropicChatClient;
use App\Ai\ChatClient;
use App\Auth\RoleSimulation;
use App\Auth\Sso\Drivers\EntraDriver;
use App\Auth\Sso\Drivers\SamlDriver;
use App\Auth\Sso\IdentityProviderType;
use App\Auth\Sso\PendingSsoLogout;
use App\Auth\Sso\SsoDriverRegistry;
use App\Filament\Pages\DataModelPage;
use App\Http\Responses\LogoutResponse;
use App\Models\Activity as AppActivity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\IdentityProvider;
use App\Models\Menu;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginPermissions;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use App\Policies\ActivityPolicy;
use App\Policies\CustomFieldPolicy;
use App\Policies\CustomTablePolicy;
use App\Policies\DatabaseConnectionPolicy;
use App\Policies\IdentityProviderPolicy;
use App\Policies\MenuPolicy;
use App\Policies\RolePolicy;
use App\Policies\SocialAccountPolicy;
use App\Policies\UserPolicy;
use App\Reports\Assistant\AnthropicAssistantClient;
use App\Reports\Assistant\AssistantClient;
use App\Support\AppLocale;
use App\Tasks\TaskRegistry;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Schemas\Schema;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Table;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;
use Spatie\Translatable\Facades\Translatable;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /** Mindestlänge neuer Passwörter (Password::defaults()). */
    public const int PASSWORD_MIN_LENGTH = 12;

    public function register(): void
    {
        // pdo_sqlsrv übernimmt beim Laden die Locale des Systems (setlocale(LC_ALL, "")). Meldungen von
        // Datenbankbibliotheken (z. B. libpq, ausgewertet im ConnectionTester) und Zahlenformate
        // bleiben neutral; LC_CTYPE (UTF-8) braucht der SQL-Server-Treiber und bleibt.
        setlocale(LC_MESSAGES, 'C');
        setlocale(LC_NUMERIC, 'C');

        // SSO-Treiber je IdP-Typ.
        $this->app->singleton(SsoDriverRegistry::class, function ($app): SsoDriverRegistry {
            $registry = new SsoDriverRegistry($app);
            $registry->register(IdentityProviderType::Entra, EntraDriver::class);
            $registry->register(IdentityProviderType::Saml, SamlDriver::class);

            return $registry;
        });

        $this->app->scoped(PendingSsoLogout::class);
        $this->app->scoped(TaskRegistry::class);
        $this->app->bind(AssistantClient::class, AnthropicAssistantClient::class);
        // Hilfe-Assistent: heute Claude; ein lokal betriebenes Modell bekäme hier eine eigene Umsetzung.
        $this->app->bind(ChatClient::class, AnthropicChatClient::class);
        $this->app->scoped(RoleSimulation::class);
        // Plugins melden sich in ihren ServiceProvidern an (die vor diesem laufen, daher singletonIf).
        $this->app->singletonIf(PluginRegistry::class);
        $this->app->singleton(PluginDependencies::class);
        $this->app->scoped(PluginStates::class);
    }

    public function boot(): void
    {
        // In Produktion keine zerstörerischen Befehle (migrate:fresh/refresh/reset, db:wipe).
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Passwörter: mindestens 12 Zeichen und – wenn eingeschaltet – nicht aus einem bekannten Datenleck. Gilt überall,
        // wo Password::defaults() greift: Benutzerformular, Passwort ändern, Passwort zurücksetzen, ADMIN_PASSWORD.
        Password::defaults(static function (): Password {
            $rule = Password::min(self::PASSWORD_MIN_LENGTH)->max(255);

            return config('saft.password_check_leaked') ? $rule->uncompromised() : $rule;
        });

        // Abhängigkeiten der Plugins neu prüfen, sobald sich Tabellen oder Felder ändern.
        foreach ([CustomTable::class, CustomField::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                Event::listen("eloquent.{$event}: {$model}", static fn () => app(PluginDependencies::class)->flush());
            }
        }

        // Rechte neuer Plugins nach jedem „migrate“ anlegen.
        Event::listen(MigrationsEnded::class, static function (MigrationsEnded $event): void {
            if ($event->method === 'up') {
                app(PluginPermissions::class)->sync();
            }
        });

        // Seiten, Ressourcen und Kacheln ausgeschalteter Plugins: „nicht gefunden“, auch bei direktem Aufruf der
        // Adresse oder einer Livewire-Anfrage einer noch offenen Seite.
        $blockDisabledPlugin = static function (object $component): void {
            abort_if(app(PluginStates::class)->blocks($component::class), 404);
        };
        on('mount', $blockDisabledPlugin);
        on('hydrate', $blockDisabledPlugin);

        // In boot(), damit Filaments eigene Bindung sicher überschrieben wird.
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);

        // Mermaid für die Seite „Datenmodell“: mitgeliefert (kein CDN), nur dort geladen.
        FilamentAsset::register([
            Js::make(DataModelPage::ASSET, resource_path('js/vendor/mermaid.min.js'))->loadedOnRequest(),
        ]);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Menu::class, MenuPolicy::class);
        // Beide Klassen: Abfragen über das Paket liefern Spatie-, über die App App-Instanzen.
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(AppActivity::class, ActivityPolicy::class);
        Gate::policy(IdentityProvider::class, IdentityProviderPolicy::class);
        Gate::policy(SocialAccount::class, SocialAccountPolicy::class);
        Gate::policy(CustomTable::class, CustomTablePolicy::class);
        Gate::policy(CustomField::class, CustomFieldPolicy::class);
        Gate::policy(DatabaseConnection::class, DatabaseConnectionPolicy::class);

        // Super-Admins erhalten alles, was nicht explizit verboten ist. "after" statt
        // "before", damit harte Policy-Verbote (z. B. Super-Admin-Rolle löschen) greifen.
        Gate::after(static fn (mixed $user, string $ability, ?bool $result): ?bool => $result
            ?? ($user instanceof User && $user->isSuperAdmin() ? true : null));

        // Filaments Vorgabe ist unabhängig von der Sprache englisch (z. B. "Feb 1, 2026").
        Table::configureUsing(self::useLocaleDateFormats(...));

        // Filter, Suche und Sortierung bleiben für die Dauer der Sitzung erhalten (z. B. nach
        // „Bearbeiten“ und zurück). Dauerhaft je Person: Spaltenauswahl (RemembersTableColumns) und – in den
        // Datenansichten – Filter, Suche, Sortierung und Zeilen pro Seite (RemembersTableState).
        Table::configureUsing(static function (Table $table): void {
            $table->persistFiltersInSession()
                ->persistSearchInSession()
                ->persistColumnSearchesInSession()
                ->persistSortInSession();
        });
        Schema::configureUsing(self::useLocaleDateFormats(...));

        // Fehlt eine Übersetzung (z. B. Menübezeichnung), gilt die Vorgabesprache, sonst irgendeine.
        Translatable::fallback(fallbackLocale: (string) config('locales.default'), fallbackAny: true);

        LanguageSwitch::configureUsing(static function (LanguageSwitch $switch): void {
            $switch
                ->locales(AppLocale::available())
                ->labels(AppLocale::labels())
                ->visible(outsidePanels: true)
                ->outsidePanelRoutes(['auth.login', 'auth.password-reset.request', 'auth.password-reset.reset']);
        });
    }

    private static function useLocaleDateFormats(Table|Schema $component): void
    {
        $component
            ->defaultDateDisplayFormat(AppLocale::setting('date'))
            ->defaultDateTimeDisplayFormat(AppLocale::setting('datetime'))
            ->defaultTimeDisplayFormat(AppLocale::setting('time'))
            // Zahlen nach dem Zahlenformat der Person (Meine Einstellungen), z. B. de_CH: 1'234.50.
            ->defaultNumberLocale(static fn (): string => AppLocale::numberLocale());
    }
}
