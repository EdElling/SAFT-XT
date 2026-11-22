<?php

declare(strict_types=1);

namespace App\Apps;

use App\Database\DatabaseBackup;
use App\DynamicData\DynamicDataNamespace;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\DocumentLetterhead;
use App\Models\Menu;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Services\DynamicSchemaService;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Entfernt eine App samt Inhalt (B-107, Etappe 3) – die gefährlichste Aktion der Administration, daher abgesichert:
 * nur der Super-Admin, nur eine deaktivierte App, zweifach bestätigt, ausgeführt frühestens nach einer Wartezeit
 * (bis dahin abbrechbar), vorher eine Sicherung der Datenbank und der Dateien. Ausgeführt vom Scheduler
 * (tasks:run-due) oder – ohne Cron – vom Super-Admin nach Ablauf der Wartezeit.
 *
 * Reihenfolge: Aufgaben, Auswertungen, Tabellenverknüpfungen, Menüs, Rollen, angebundene Tabellen (nur die Anbindung),
 * Designer-Tabellen mit Daten und Dateien, Handbuch, Einstellungen der Personen, die App. Jeder Schritt ist für sich
 * abgeschlossen; scheitert einer, bleibt die Vormerkung mit der Meldung bestehen und ein neuer Lauf setzt fort.
 */
final readonly class AppRemover
{
    /** Wartezeit zwischen Vormerken und Ausführen in Minuten (mindestens). */
    public const int DELAY_MINUTES = 10;

    public function __construct(
        private DynamicSchemaService $schema,
        private TableConnectorService $connector,
        private DatabaseBackup $backup,
    ) {}

    /**
     * Was beim Entfernen verloren geht – für die Übersicht vor der Bestätigung.
     *
     * @return array{tables: list<array{label: string, name: string, records: int|null}>, connected: list<string>, menus: int, reports: int, report_links: int, roles: list<array{name: string, members: int}>, tasks: int, manual: bool, users_without_app: int, plugins: list<string>}
     */
    public function overview(App $app): array
    {
        $namespace = app(DynamicDataNamespace::class);
        $tables = [];
        $connected = [];

        foreach ($app->customTables()->orderBy('name')->get() as $table) {
            if ($table->isExternal()) {
                $connected[] = (string) $table->label;

                continue;
            }

            try {
                $records = $namespace->connection()->table($namespace->qualify($table->name))->count();
            } catch (Throwable) {
                $records = null;
            }

            $tables[] = ['label' => (string) $table->label, 'name' => $table->name, 'records' => $records];
        }

        return [
            'tables' => $tables,
            'connected' => $connected,
            'menus' => $app->menus()->count(),
            'reports' => $app->reports()->count(),
            'report_links' => $app->reportLinks()->count(),
            'roles' => $app->roles()->withCount('users')->orderBy('name')->get()
                ->map(static fn ($role): array => ['name' => (string) $role->name, 'members' => (int) $role->users_count])->values()->all(),
            'tasks' => $app->scheduledTasks()->count(),
            'manual' => $app->manuals()->exists(),
            'users_without_app' => $this->usersWithoutApp($app),
            'plugins' => array_values(array_map(
                static fn ($plugin): string => $plugin->label(),
                array_filter(app(PluginRegistry::class)->all(), static fn ($plugin): bool => app(PluginApps::class)->appIdFor($plugin) === $app->id),
            )),
        ];
    }

    /**
     * Was das Entfernen verhindert (leer = möglich).
     *
     * @return list<string>
     */
    public function blockers(App $app): array
    {
        $blockers = [];

        if ($app->is_active) {
            $blockers[] = __('apps.removal.deactivate_first');
        }

        $designer = $app->customTables()->get()->reject(static fn (CustomTable $t): bool => $t->isExternal())->values();

        return [...$blockers, ...$this->schema->dropTablesBlockers($designer)];
    }

    /** Vormerken (nach der zweifachen Bestätigung): frühestens nach der Wartezeit ausführen. */
    public function request(App $app, User $user, string $confirmation): void
    {
        $this->assertSuperAdmin($user);

        if (! hash_equals((string) $app->name, trim($confirmation))) {
            throw new BusinessRuleException(__('apps.removal.confirmation_mismatch', ['name' => (string) $app->name]));
        }

        if (($blockers = $this->blockers($app)) !== []) {
            throw new BusinessRuleException(implode(' ', $blockers));
        }

        $app->forceFill([
            'removal_due_at' => now()->addMinutes(self::DELAY_MINUTES),
            'removal_requested_by' => $user->id,
            'removal_error' => null,
        ])->save();

        activity('apps')->causedBy($user)->performedOn($app)->event('removal_requested')
            ->withProperties(['due' => $app->removal_due_at?->toIso8601String()])
            ->log(__('apps.removal.log_requested', ['name' => (string) $app->name]));
    }

    public function cancel(App $app, User $user): void
    {
        $this->assertSuperAdmin($user);
        $app->forceFill(['removal_due_at' => null, 'removal_requested_by' => null, 'removal_error' => null])->save();

        activity('apps')->causedBy($user)->performedOn($app)->event('removal_cancelled')
            ->log(__('apps.removal.log_cancelled', ['name' => (string) $app->name]));
    }

    /**
     * Ausführen – nur vorgemerkt und nach Ablauf der Wartezeit. Gibt den Namen der Sicherung zurück.
     *
     * @throws BusinessRuleException
     */
    public function run(App $app, ?User $user = null): string
    {
        if ($user !== null) {
            $this->assertSuperAdmin($user);
        }

        if (! $app->removalDue()) {
            throw new BusinessRuleException(__('apps.removal.not_due'));
        }

        $name = (string) $app->name;

        try {
            if (($blockers = $this->blockers($app)) !== []) {
                throw new BusinessRuleException(implode(' ', $blockers));
            }

            @set_time_limit(0);
            $dump = $this->backup->create();
            $this->backup->backupFiles($dump);

            $this->removeContents($app);
            $app->delete();
        } catch (Throwable $e) {
            $app->forceFill(['removal_error' => $e->getMessage()])->save();
            Log::error('App entfernen fehlgeschlagen', ['app' => $app->id, 'error' => $e->getMessage()]);

            throw $e instanceof BusinessRuleException ? $e : new BusinessRuleException(__('apps.removal.failed', ['error' => $e->getMessage()]), previous: $e);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('apps')->causedBy($user ?? $app->removalRequester)->event('removed')
            ->withProperties(['app' => $name, 'uuid' => $app->uuid, 'backup' => basename($dump)])
            ->log(__('apps.removal.log_removed', ['name' => $name, 'backup' => basename($dump)]));

        return basename($dump);
    }

    /**
     * Alle fälligen Entfernungen ausführen (Scheduler).
     *
     * @return list<string> Meldungen
     */
    public function runDue(): array
    {
        $messages = [];

        foreach (App::query()->whereNotNull('removal_due_at')->where('removal_due_at', '<=', now())->whereNull('removal_error')->get() as $app) {
            $name = (string) $app->name;

            try {
                $backup = $this->run($app);
                $messages[] = __('apps.removal.done', ['name' => $name, 'backup' => $backup]);
            } catch (BusinessRuleException $e) {
                $messages[] = $name.': '.$e->getMessage();
            }
        }

        return $messages;
    }

    private function removeContents(App $app): void
    {
        $app->scheduledTasks()->get()->each->delete();
        $app->reports()->get()->each->delete();
        $app->reportLinks()->get()->each->delete();

        // Menüs: zuerst die Einträge, dann die Gruppen von unten nach oben (Untermenüs fallen mit).
        Menu::query()->where('app_id', $app->id)->whereNotNull('route')->get()->each->delete();
        Menu::query()->where('app_id', $app->id)->orderByDesc('parent_id')->get()->each(static fn (Menu $menu): ?bool => Menu::query()->whereKey($menu->id)->exists() ? $menu->delete() : null);

        $app->roles()->get()->each->delete();

        $tables = $app->customTables()->get();
        $tables->filter(static fn (CustomTable $t): bool => $t->isExternal())->each(fn (CustomTable $t) => $this->connector->disconnect($t));
        $this->schema->dropTables($tables->reject(static fn (CustomTable $t): bool => $t->isExternal())->values());

        $app->manuals()->delete();
        // Briefpapier der App einzeln löschen, damit seine Dateien mitgehen (B-124).
        DocumentLetterhead::query()->where('app_id', $app->id)->get()->each->delete();

        // Einstellungen der Personen: aktuelle App und Dashboard-Anordnung dieser App.
        User::query()->where('current_app_id', $app->id)->update(['current_app_id' => null]);
        User::query()->whereNotNull('dashboard_layout')->get()->each(static function (User $user) use ($app): void {
            $layout = $user->dashboard_layout;

            if (is_array($layout) && isset($layout['apps']) && array_key_exists((string) $app->id, $layout['apps'])) {
                unset($layout['apps'][(string) $app->id]);
                // Ohne Änderungsprotokoll: eine Bedienungs-Einstellung.
                $user->dashboard_layout = $layout;
                $user->saveQuietly();
            }
        });
    }

    /** Personen, die nach dem Entfernen keine App mehr haben (ohne Super-Admins). */
    private function usersWithoutApp(App $app): int
    {
        $current = app(CurrentApp::class);

        return User::query()->whereHas('roles', static fn ($q) => $q->where('app_id', $app->id))->get()
            ->filter(static fn (User $user): bool => ! $user->isSuperAdmin() && $current->accessible($user)->where('id', '!=', $app->id)->isEmpty())
            ->count();
    }

    private function assertSuperAdmin(User $user): void
    {
        if (! $user->isSuperAdmin()) {
            throw new BusinessRuleException(__('apps.removal.super_admin_only'));
        }
    }
}
