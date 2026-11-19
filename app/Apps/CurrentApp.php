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
    /** @var array<int, App|null> */
    private array $resolved = [];

    /** @return Collection<int, App> zugängliche Apps in ihrer Reihenfolge */
    public function accessible(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        $query = App::query()->orderBy('sort_order')->orderBy('id');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('id', $this->appIds($user));
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
        $tables = $permissions
            ->map(static fn ($permission): ?string => str_starts_with($permission->name, DataPermissions::PREFIX)
                ? explode('.', substr($permission->name, strlen(DataPermissions::PREFIX)))[0]
                : null)
            ->filter()->unique()->values()->all();
        $fromData = $tables === [] ? [] : CustomTable::query()->whereIn('name', $tables)->whereNotNull('app_id')->pluck('app_id')->all();

        return array_values(array_unique(array_map('intval', [...$fromRoles, ...$fromData, ...$fromPlugins])));
    }

    public function get(?User $user): ?App
    {
        if ($user === null) {
            return null;
        }

        if (array_key_exists($user->id, $this->resolved)) {
            return $this->resolved[$user->id];
        }

        $apps = $this->accessible($user);

        return $this->resolved[$user->id] = $apps->firstWhere('id', $user->current_app_id) ?? $apps->first();
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

        $this->resolved[$user->id] = $target;

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
