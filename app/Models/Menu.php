<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\DataMenu;
use App\Filament\Pages\DataModelPage;
use App\Models\Base\Menu as BaseMenu;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasTranslatedAttributes;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends BaseMenu
{
    use HasAuditLog;
    use HasTranslatedAttributes;

    /** Bezeichnung je Sprache; fehlt sie, erscheint eine vorhandene (siehe AppServiceProvider). */
    public array $translatable = ['label'];

    /** Spiegelt die DB-Defaults, damit das Audit-Log keine Phantom-Änderungen erzeugt. */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * Sortierung nach der Bezeichnung in der aktuellen Sprache. JSON-Spalten selbst sind in
     * PostgreSQL nicht sortierbar.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function orderedByLabel(Builder $query): void
    {
        $query->orderBy('label->'.app()->getLocale())->orderBy('label->'.config('locales.default'));
    }

    protected static function booted(): void
    {
        // Datenmodell-Einträge verweisen auf sich selbst; die Seite ermittelt daraus ihre Gruppe.
        static::saved(static function (self $menu): void {
            $own = ['menu' => (int) $menu->id];

            if ($menu->route === DataModelPage::ROUTE && ($menu->route_parameters ?? []) !== $own) {
                // Direkt schreiben: im saved-Ereignis ist das Original noch der Stand vor dem Speichern,
                // ein erneutes save() hielte den Wert womöglich für unverändert.
                $menu->newQuery()->whereKey($menu->id)->update(['route_parameters' => json_encode($own)]);
                $menu->route_parameters = $own;
                $menu->syncOriginalAttribute('route_parameters');
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->menu();
    }

    public function children(): HasMany
    {
        return $this->menus()->orderBy('sort_order');
    }

    /** Rollen, die diesen Eintrag ausblenden. */
    public function hiddenForRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'menu_role_hidden');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Einträge, die der User sehen darf: ohne Permission oder mit einer Permission,
     * die der User (direkt oder über Rollen) besitzt. Super-Admins sehen alles.
     *
     * Datentabellen-Einträge entfallen zusätzlich, wenn jede Rolle des Users, die das Leserecht
     * gibt, den Eintrag ausblendet. Das ist nur Darstellung, keine Rechtegrenze.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleFor(Builder $query, User $user): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $permissions = $user->getAllPermissions()->pluck('name')->all();

        $query->where(function (Builder $q) use ($permissions): void {
            $q->whereNull('permission_name')
                ->orWhereIn('permission_name', $permissions);
        });

        $hidden = self::hiddenDataEntriesFor($user);

        if ($hidden !== []) {
            $query->whereKeyNot($hidden);
        }
    }

    /**
     * Additiv wie die Rechte: Sichtbar bleibt ein Eintrag, wenn ein direktes Recht oder mindestens
     * eine Rolle mit dem Leserecht ihn nicht ausblendet.
     *
     * @return list<int>
     */
    public static function hiddenDataEntriesFor(User $user): array
    {
        // Geladene Rollen statt neuer Abfrage: Die „Ansicht als Rolle“ ersetzt sie im Speicher.
        $roles = $user->roles->loadMissing(['permissions:id,name', 'hiddenMenus:id']);
        $candidates = $roles->flatMap(fn (Role $role) => $role->hiddenMenus->modelKeys())->unique()->values()->all();

        if ($candidates === []) {
            return [];
        }

        $direct = $user->getDirectPermissions()->pluck('name')->all();

        return self::query()
            ->where('route', DataMenu::ROUTE)
            ->whereKey($candidates)
            ->get(['id', 'permission_name'])
            ->filter(function (self $menu) use ($roles, $direct): bool {
                if ($menu->permission_name === null || in_array($menu->permission_name, $direct, true)) {
                    return false;
                }

                $granting = $roles->filter(fn (Role $role): bool => $role->permissions->contains('name', $menu->permission_name));

                return $granting->isNotEmpty()
                    && $granting->every(fn (Role $role): bool => $role->hiddenMenus->contains('id', $menu->id));
            })
            ->map(fn (self $menu): int => (int) $menu->id)
            ->values()
            ->all();
    }
}
