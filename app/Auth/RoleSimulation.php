<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * „Ansicht als Rolle“: Ein Super-Admin sieht SAFT mit genau den Rechten einer oder mehrerer Rollen
 * (z. B. „benutzer“ + „forschung“, wie eine Person sie tatsächlich hat). Die Wahl steht nur in der
 * Sitzung; je Anfrage ersetzt die Middleware die geladenen Rollen und direkten Rechte des Benutzers
 * im Speicher (die Datenbank bleibt unverändert). Damit greifen Spatie-Rechte, Policies und die
 * Super-Admin-Sonderregel (Gate::after) wie für die Rollen. Eigentümerschaft (eigene Auswertungen)
 * zählt währenddessen nicht – eine andere Person mit diesen Rollen hätte sie auch nicht.
 */
final class RoleSimulation
{
    public const string SESSION_KEY = 'saft.simulated_roles';

    /** @var Collection<int, Role>|null */
    private ?Collection $active = null;

    /** Darf diese Person simulieren? Maßgeblich sind die echten Rollen in der Datenbank. */
    public function canSimulate(User $user): bool
    {
        return $user->roles()->where('name', User::ROLE_SUPER_ADMIN)->exists();
    }

    /** @param  iterable<Role>  $roles */
    public function start(User $user, iterable $roles): void
    {
        $roles = collect($roles);
        abort_unless($this->canSimulate($user) && $roles->isNotEmpty() && $roles->every(fn (Role $role): bool => $role->name !== User::ROLE_SUPER_ADMIN), 403);

        session()->put(self::SESSION_KEY, $roles->map(fn (Role $role): int => (int) $role->getKey())->values()->all());
        activity('auth')->causedBy($user)->event('role_simulation_started')->withProperties(['rollen' => $roles->pluck('name')->all()])->log('role_simulation_started');
    }

    public function stop(User $user): void
    {
        $roles = Role::query()->whereKey((array) session()->pull(self::SESSION_KEY, []))->pluck('name');

        if ($roles->isNotEmpty()) {
            activity('auth')->causedBy($user)->event('role_simulation_stopped')->withProperties(['rollen' => $roles->all()])->log('role_simulation_stopped');
        }

        // Ersetzte Rollen im Speicher verwerfen: Sie werden beim nächsten Zugriff neu geladen.
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $this->active = null;
    }

    /** Für die laufende Anfrage anwenden (Middleware); ungültige Wahl wird verworfen. */
    public function apply(User $user): void
    {
        $ids = session(self::SESSION_KEY);

        if ($ids === null) {
            return;
        }

        if (! $this->applyRoles($user, array_map('intval', (array) $ids))) {
            session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Die Rollen ohne Sitzung anwenden, z. B. für eine Arbeit, die unter „Ansicht als Rolle“ gestartet wurde und im
     * Hintergrund weiterläuft (Fundament 2, F3a). Ungültige Wahl → false, nichts angewendet.
     *
     * @param  list<int>  $roleIds
     */
    public function applyRoles(User $user, array $roleIds): bool
    {
        $roles = Role::query()->with('permissions')->whereKey($roleIds)->orderBy('name')->get();

        if ($roles->isEmpty() || $roles->contains('name', User::ROLE_SUPER_ADMIN) || ! $this->canSimulate($user)) {
            return false;
        }

        $user->setRelation('roles', $roles);
        $user->setRelation('permissions', new Collection);
        $this->active = $roles;

        return true;
    }

    /**
     * Den Stand vor applyRoles() wiederherstellen (Arbeiten im Scheduler: die nächste Arbeit läuft ohne Simulation).
     *
     * @param  Collection<int, Role>|null  $active  der frühere Wert von active()
     *
     * @internal
     */
    public function restore(?Collection $active): void
    {
        $this->active = $active;
    }

    /** @return Collection<int, Role>|null die simulierten Rollen oder null */
    public function active(): ?Collection
    {
        return $this->active;
    }

    public function isActive(): bool
    {
        return $this->active !== null;
    }
}
