<?php

declare(strict_types=1);

namespace App\Apps;

use App\DynamicData\DataPermissions;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\User;
use App\Plugins\PluginRegistry;
use Illuminate\Support\Collection;

/**
 * Die aktuelle App einer Person (B-107, Etappe 2): bestimmt Navigation, Dashboard, Auswertungen und Handbücher.
 * Zugang haben Personen über ihre Rollen, ihre Datenrechte und die Rechte von Plugins einer App (Super-Admin: alle Apps; bei „Ansicht als Rolle“ die
 * simulierten Rollen).
 * Gemerkt wird die zuletzt gewählte App (users.current_app_id), auch über die Abmeldung hinaus; ohne gültige Wahl gilt
 * die erste zugängliche. Einmal je Anfrage berechnet.
 */
final class CurrentApp
{
    /** @var array<string, App|null> Person:gewählte App => App */
    private array $resolved = [];

    /** @return Collection<int, App> zugängliche Apps in ihrer Reihenfolge */
    public function accessible(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        $query = App::query()->orderBy('sort_order')->orderBy('id');

        // Deaktivierte Apps (Etappe 3) nur für den Super-Admin, etwa um eine neue App vor der Freigabe aufzubauen.
        if (! $user->isSuperAdmin()) {
            $query->whereIn('id', $this->appIds($user))->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Apps der Rollen der Person und Apps der Tabellen, auf die sie Datenrechte hat (über eine App-Rolle, eine
     * Systemrolle wie „benutzer“ oder direkt) – so verliert nach der Umstellung niemand den Zugang.
     *
     * @return list<int>
     */
    private function appIds(User $user): array
    {
        $fromRoles = $user->roles->pluck('app_id')->filter()->all();
        $permissions = $user->getAllPermissions();
        // Rechte eines Plugins geben Zugang zur App des Plugins (PluginApps).
        $plugins = app(PluginRegistry::class);
        $fromPlugins = $permissions
            ->map(static fn ($permission): ?int => ($plugin = $plugins->forPermission($permission->name)) === null ? null : app(PluginApps::class)->appIdFor($plugin))
            ->filter()->all();
        $table = static fn (string $permission): ?string => str_starts_with($permission, DataPermissions::PREFIX)
            ? explode('.', substr($permission, strlen(DataPermissions::PREFIX)))[0]
            : null;
        $tables = $permissions->map(static fn ($permission): ?string => $table($permission->name))->filter()->unique()->values()->all();
        $byName = $tables === [] ? collect() : CustomTable::query()->whereIn('name', $tables)->whereNotNull('app_id')->get(['name', 'app_id', 'is_shared'])->keyBy('name');

        // Leserecht auf eine freigegebene Tabelle (Schnittstelle) über die Rolle einer anderen App gibt keinen Zugang zur
        // App der Tabelle – sonst stünde jede genutzte App im Umschalter.
        $viaOtherApp = [];

        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $target = $byName->get((string) $table($permission->name));

                if ($target !== null && $target->is_shared && $role->app_id !== null && $role->app_id !== $target->app_id) {
                    $viaOtherApp[$permission->name] = true;
                }
            }
        }

        $direct = $user->getDirectPermissions()->pluck('name')->all();
        $fromData = [];

        foreach ($user->roles->flatMap->permissions->concat($user->getDirectPermissions()) as $permission) {
            $target = $byName->get((string) $table($permission->name));

            if ($target !== null && (! isset($viaOtherApp[$permission->name]) || in_array($permission->name, $direct, true) || self::heldByOwnRole($user, $permission->name, $target->app_id))) {
                $fromData[] = $target->app_id;
            }
        }

        return array_values(array_unique(array_map('intval', [...$fromRoles, ...$fromData, ...$fromPlugins])));
    }

    /** Hat die Person das Recht (auch) über eine Rolle der App der Tabelle oder eine Systemrolle? */
    private static function heldByOwnRole(User $user, string $permission, int $appId): bool
    {
        return $user->roles->contains(static fn ($role): bool => ($role->app_id === null || $role->app_id === $appId) && $role->permissions->contains('name', $permission));
    }

    public function get(?User $user): ?App
    {
        if ($user === null) {
            return null;
        }

        // Je Person und gewählter App: wer current_app_id am Model setzt, bekommt die neue App (CurrentApp ist scoped).
        $key = $user->id.':'.$user->current_app_id;

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $apps = $this->accessible($user);

        return $this->resolved[$key] = $apps->firstWhere('id', $user->current_app_id) ?? $apps->first();
    }

    /** Nach Änderungen an Apps: neu ermitteln (CurrentApp ist je Request scoped). */
    public function forget(): void
    {
        $this->resolved = [];
    }

    public function id(?User $user): ?int
    {
        return $this->get($user)?->id;
    }

    /** Wechsel; nur zu zugänglichen Apps. Gibt zurück, ob gewechselt wurde. */
    public function switch(User $user, App|int|null $app): bool
    {
        $id = $app instanceof App ? $app->id : $app;
        $target = $id === null ? null : $this->accessible($user)->firstWhere('id', $id);

        if ($target === null) {
            return false;
        }

        if ($user->current_app_id !== $target->id) {
            // Ohne Änderungsprotokoll: eine Bedienungs-Einstellung, keine fachliche Änderung.
            $user->newQuery()->whereKey($user->id)->update(['current_app_id' => $target->id]);
            $user->current_app_id = $target->id;
            $user->syncOriginalAttribute('current_app_id');
        }

        $this->resolved[$user->id.':'.$user->current_app_id] = $target;

        return true;
    }

    /** Ein geteilter Link auf ein Objekt einer anderen App schaltet in diese App (sofern zugänglich). */
    public function follow(?User $user, ?int $appId): void
    {
        if ($user !== null && $appId !== null && $this->id($user) !== $appId) {
            $this->switch($user, $appId);
        }
    }
}
