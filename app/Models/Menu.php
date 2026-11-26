<?php

declare(strict_types=1);

namespace App\Models;

use App\Apps\SystemObjects;
use App\DynamicData\DataMenu;
use App\Filament\Pages\DataModelPage;
use App\Models\Base\Menu as BaseMenu;
use App\Models\Concerns\BelongsToApp;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Models\Concerns\HasTranslatedAttributes;
use App\Navigation\MenuNavigationBuilder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends BaseMenu
{
    use BelongsToApp;
    use HasAuditLog;
    use HasStableKey;
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

    /** Während der Grundausstattung (MenuSeeder) entstehen System-Menüs ohne App. */
    private static bool $systemMenus = false;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function asSystemMenus(callable $callback): mixed
    {
        $previous = self::$systemMenus;
        self::$systemMenus = true;

        try {
            return $callback();
        } finally {
            self::$systemMenus = $previous;
        }
    }

    protected static function booted(): void
    {
        // Fingerabdruck der Menüs für die Navigation (MenuNavigationBuilder::cachedForRequest) neu ermitteln.
        $forget = static fn () => request()->attributes->remove(MenuNavigationBuilder::FINGERPRINT);
        static::saved($forget);
        static::deleted($forget);

        // App eines Eintrags (B-107): Dateneinträge gehören zur App ihrer Tabelle, andere Einträge zur App ihrer Gruppe;
        // nur oberste Gruppen wählen sie selbst. System-Menüs haben keine.
        static::saving(static function (self $menu): void {
            $table = $menu->route === DataMenu::ROUTE ? (string) (($menu->route_parameters ?? [])['table'] ?? '') : '';
            $appId = $table !== '' ? CustomTable::query()->where('name', $table)->value('app_id') : null;

            if ($appId === null && $menu->parent_id !== null) {
                $appId = self::query()->whereKey($menu->parent_id)->value('app_id');
            }

            if ($appId !== null) {
                $menu->app_id = (int) $appId;
            } elseif (! $menu->exists && $menu->app_id === null && $menu->parent_id === null && ! self::$systemMenus && ! SystemObjects::isSystemRoute($menu->route)) {
                // Neue oberste Einträge: bei genau einer App gehören sie zu ihr.
                $menu->app_id = App::onlyId();
            }
        });

        // Datenmodell-Einträge verweisen auf sich selbst; die Seite ermittelt daraus ihre Gruppe.
        // Neue App einer Gruppe (z. B. im Menüformular): Untergruppen und Einträge darunter ziehen mit – ausser
        // Dateneinträgen, die immer zur App ihrer Tabelle gehören (B-107).
        static::saved(static function (self $menu): void {
            if ($menu->route !== null || ! $menu->wasChanged('app_id')) {
                return;
            }

            $queue = [(int) $menu->id];

            while ($queue !== []) {
                $children = self::query()->whereIn('parent_id', $queue)->get(['id', 'route']);
                $ids = $children->filter(static fn (self $c): bool => $c->route !== DataMenu::ROUTE)->pluck('id')->all();

                if ($ids !== []) {
                    self::query()->whereKey($ids)->update(['app_id' => $menu->app_id]);
                }

                $queue = $children->whereNull('route')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            }
        });

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

    /** @return BelongsTo<Menu, $this> */
    public function parent(): BelongsTo
    {
        return $this->menu();
    }

    /** @return HasMany<Menu, $this> */
    public function children(): HasMany
    {
        return $this->menus()->orderBy('sort_order');
    }

    /** @return BelongsToMany<Role, $this> Rollen, die diesen Eintrag ausblenden. */
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
            ->whereKey($candidates)
            ->get(['id', 'route', 'permission_name', 'app_id'])
            ->filter(function (self $menu) use ($roles, $direct): bool {
                // Datentabellen (mit Leserecht): zählen die Rollen, die das Recht geben.
                if ($menu->route === DataMenu::ROUTE) {
                    if ($menu->permission_name === null || in_array($menu->permission_name, $direct, true)) {
                        return false;
                    }

                    $granting = $roles->filter(fn (Role $role): bool => $role->permissions->contains('name', $menu->permission_name));

                    return $granting->isNotEmpty()
                        && $granting->every(fn (Role $role): bool => $role->hiddenMenus->contains('id', $menu->id));
                }

                // Andere Einträge (Datenmodell, Auswertung, Seite, Link; B-112): ausgeblendet, wenn alle Rollen der Person
                // aus der App des Eintrags ihn ausblenden.
                $own = $roles->filter(fn (Role $role): bool => $menu->app_id !== null && $role->app_id === $menu->app_id);

                return $own->isNotEmpty() && $own->every(fn (Role $role): bool => $role->hiddenMenus->contains('id', $menu->id));
            })
            ->map(fn (self $menu): int => (int) $menu->id)
            ->values()
            ->all();
    }
}
