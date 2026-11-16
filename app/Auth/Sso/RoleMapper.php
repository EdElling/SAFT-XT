<?php

declare(strict_types=1);

namespace App\Auth\Sso;

use App\Exceptions\BusinessRuleException;
use App\Models\IdentityProvider;
use App\Models\IdentityProviderRoleMapping;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Leitet Rollen aus IdP-Werten (Entra-Gruppen/App-Rollen, SAML-Attribut) ab.
 *
 * "Verwaltet" sind alle Rollen, die in den Zuordnungen des IdP vorkommen: sie werden
 * bei jedem Login vergeben bzw. entzogen. Alle anderen Rollen bleiben unberührt.
 * Die Rolle super-admin ist nie zuordenbar.
 */
final class RoleMapper
{
    public function plan(IdentityProvider $provider, SsoIdentity $identity): RoleSyncPlan
    {
        if (! $provider->setting('role_sync')) {
            return RoleSyncPlan::skip();
        }

        if ($identity->roleValues === null) {
            // Werte nicht verfügbar (z. B. Gruppen-Überlauf): lieber nichts ändern als alles entziehen.
            Log::warning('Rollen-Mapping übersprungen: IdP lieferte keine auswertbaren Werte', ['identity_provider' => $provider->slug]);

            return RoleSyncPlan::skip();
        }

        $values = array_flip(array_map(IdentityProviderRoleMapping::normalize(...), $identity->roleValues));
        $superAdminId = Role::query()->where('name', User::ROLE_SUPER_ADMIN)->value('id');

        $managed = [];
        $matched = [];

        foreach ($provider->roleMappings()->get(['external_value', 'role_id']) as $mapping) {
            if ($mapping->role_id === $superAdminId) {
                continue;
            }

            $managed[$mapping->role_id] = true;

            if (isset($values[IdentityProviderRoleMapping::normalize($mapping->external_value)])) {
                $matched[$mapping->role_id] = true;
            }
        }

        if ($matched === [] && $provider->setting('require_role_match')) {
            throw new BusinessRuleException(__('auth_admin.errors.no_role_match'), 'sso.no_role_match');
        }

        return new RoleSyncPlan(true, array_keys($managed), array_keys($matched));
    }

    public function apply(RoleSyncPlan $plan, User $user, IdentityProvider $provider): void
    {
        if (! $plan->applicable) {
            return;
        }

        $current = $user->roles()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $target = array_values(array_unique([...array_diff($current, $plan->managedRoleIds), ...$plan->matchedRoleIds]));

        sort($current);
        sort($target);

        if ($current === $target) {
            return;
        }

        $before = $user->roles()->pluck('name')->all();
        $user->syncRoles(Role::query()->whereKey($target)->get());
        $after = $user->roles()->pluck('name')->all();

        $user->logRelationChange('roles', $before, $after);

        Log::info('Rollen per SSO-Mapping aktualisiert', [
            'user_id' => $user->id,
            'identity_provider' => $provider->slug,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
