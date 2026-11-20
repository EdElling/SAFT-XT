<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Apps\PluginApps;
use App\Apps\SystemObjects;
use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Filament\Forms\Components\DataPermissionMatrix;
use App\Filament\Support\AppSelect;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Role;
use App\Plugins\PluginRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    /** Bereiche der Funktionsrechte; der Schlüssel der Sprachdatei ersetzt Punkte durch Unterstriche. */
    /** Bereiche der allgemeinen Rechte von SAFT selbst (ohne data.* und ohne Plugins). */
    public const array RESOURCES = ['users', 'roles', 'menus', 'activity_log', 'identity_providers', 'settings.auth', 'settings.app', 'widgets', 'custom_tables', 'database_connections', 'table_connector', 'reports', 'report_links', 'tasks', 'plugins', 'apps', 'help', 'system_log'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('common.role'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('common.name'))
                            ->required()
                            ->maxLength(125)
                            ->alphaDash()
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('guard_name', 'web')),
                        Hidden::make('guard_name')
                            ->default('web'),
                        // App (B-107): Rollen gehören zu genau einer App; Systemrollen (Super-Admin, Standardrolle) zu keiner.
                        AppSelect::make()
                            ->required(fn (?Role $record): bool => ! SystemObjects::isSystemRole($record?->name))
                            ->disabled(fn (?Role $record): bool => SystemObjects::isSystemRole($record?->name))
                            ->placeholder(__('apps.system'))
                            ->helperText(__('roles.form.app_help'))
                            // Rechte von Plugins der bisherigen App sind nun „ausserhalb“: aus der Liste nehmen (sie
                            // bleiben gespeichert und erscheinen im eigenen Abschnitt).
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state, ?Role $record): void {
                                // Angebotene Rechte der neuen App: bisherige Auswahl plus gespeicherte Rechte der Rolle, die nun
                                // dazugehören (z. B. eines Plugins der neuen App) – sonst gingen sie beim Speichern verloren.
                                $stored = $record?->permissions()->pluck('permissions.id')->map(static fn (mixed $id): string => (string) $id)->all() ?? [];
                                $set('permissions', array_values(array_intersect(
                                    array_unique([...array_map('strval', (array) $get('permissions')), ...$stored]),
                                    array_map('strval', array_keys(self::permissionOptions(self::appId($state)))),
                                )));
                                // Was nun ausserhalb liegt, ist zunächst behalten (angehakt).
                                $set('other_permissions', array_map('strval', array_keys(self::otherPermissionOptions($record, self::appId($state)))));
                                // Menüeinträge der neuen App: sichtbar, ausser die Rolle blendet sie bereits aus.
                                $hidden = $record?->hiddenMenus()->pluck('menus.id')->all() ?? [];
                                $set('visible_menus', array_values(array_map('strval', array_diff(array_keys(self::dataMenuOptions(self::appId($state))), $hidden))));
                            }),
                    ]),
                Section::make(__('common.permissions'))
                    ->description(__('roles.form.permissions_help'))
                    ->schema([
                        CheckboxList::make('permissions')
                            ->hiddenLabel()
                            ->relationship(
                                'permissions',
                                'name',
                                // Ohne data.*: Filament entfernt beim Speichern nur Rechte dieser
                                // Abfrage – die Datenrechte-Matrix bleibt so unberührt.
                                // Ohne Rechte von Plugins anderer Apps: diese bleiben ebenso unberührt (eigener Abschnitt).
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                    ->where('guard_name', 'web')
                                    ->where('name', 'not like', DataPermissions::PREFIX.'%')
                                    ->whereNotIn('name', self::otherAppPluginPermissions(self::appId($get('app_id'))))
                                    ->orderBy('name'),
                            )
                            // Nach der angezeigten Bezeichnung sortiert (nicht nach dem technischen Namen):
                            // die Rechte eines Bereichs stehen beieinander, spaltenweise von oben nach unten.
                            ->options(fn (Get $get): array => self::permissionOptions(self::appId($get('app_id'))))
                            ->columns(4)
                            ->bulkToggleable()
                            ->searchable(),
                    ]),
                Section::make(__('roles.form.data_permissions'))
                    ->description(__('roles.form.data_permissions_help'))
                    ->schema([
                        Callout::make(__('roles.form.choose_app_first'))
                            ->warning()
                            ->visible(fn (Get $get): bool => blank($get('app_id'))),
                        DataPermissionMatrix::make('data_permissions')->hiddenLabel()->app(fn (Get $get): mixed => $get('app_id'))
                            ->visible(fn (Get $get): bool => filled($get('app_id'))),
                    ])
                    // Systemrollen enthalten nur app-übergreifende Rechte (B-107).
                    ->visible(fn (?Role $record): bool => CustomTable::query()->exists() && ! SystemObjects::isSystemRole($record?->name)),
                // Freigegebene Tabellen anderer Apps (B-107, Schnittstellen): nur „Anzeigen“ – zum Öffnen der fremden Tabelle;
                // die freigegebenen Felder in Verknüpfungen sieht man auch ohne dieses Recht.
                Section::make(__('roles.form.shared_permissions'))
                    ->description(__('roles.form.shared_permissions_help'))
                    ->schema([DataPermissionMatrix::make('shared_permissions')->hiddenLabel()->shared()->app(fn (Get $get): mixed => $get('app_id'))])
                    ->visible(fn (Get $get, ?Role $record): bool => ! SystemObjects::isSystemRole($record?->name) && filled($get('app_id'))
                        && CustomTable::query()->where('is_shared', true)->whereNotNull('app_id')->where('app_id', '!=', (int) $get('app_id'))->exists()),
                Callout::make(__('roles.form.system_role_hint'))
                    ->info()
                    ->visible(fn (?Role $record): bool => SystemObjects::isSystemRole($record?->name)),
                Section::make(__('roles.form.menu_entries'))
                    ->description(__('roles.form.menu_entries_help'))
                    ->schema([
                        CheckboxList::make('visible_menus')
                            ->hiddenLabel()
                            ->options(fn (Get $get): array => self::dataMenuOptions(self::appId($get('app_id'))))
                            ->columns(3)
                            ->gridDirection('row')
                            ->bulkToggleable()
                            ->dehydrated(false)
                            // Gespeichert wird nur das Ausblenden: Ohne Datensatz ist alles angehakt.
                            ->default(fn (Get $get): array => array_map('strval', array_keys(self::dataMenuOptions(self::appId($get('app_id'))))))
                            ->loadStateFromRelationshipsUsing(function (CheckboxList $component, ?Role $record): void {
                                $hidden = $record?->hiddenMenus()->pluck('menus.id')->all() ?? [];
                                $component->state(array_values(array_map('strval', array_diff(array_keys(self::dataMenuOptions($record?->app_id)), $hidden))));
                            })
                            ->saveRelationshipsUsing(function (CheckboxList $component, Role $record): void {
                                // Nur Einträge der App der Rolle ändern; ausgeblendete anderer Apps bleiben.
                                $offered = array_keys(self::dataMenuOptions($record->app_id));
                                $visible = array_map('intval', (array) $component->getState());
                                $others = $record->hiddenMenus()->pluck('menus.id')->map(static fn (mixed $id): int => (int) $id)->diff($offered)->all();
                                $record->hiddenMenus()->sync(array_values([...$others, ...array_diff($offered, $visible)]));
                            }),
                    ])
                    ->visible(fn (Get $get, ?Role $record): bool => ! SystemObjects::isSystemRole($record?->name) && self::dataMenuOptions(self::appId($get('app_id'))) !== []),
                // Vorhandene Rechte ausserhalb der App der Rolle: Tabellen anderer Apps als Matrix (nur vorhandene Rechte,
                // nur zum Entfernen), Plugins anderer Apps und gelöschte Tabellen als Liste – damit sich ältere Rollen
                // bereinigen lassen. Steht zuletzt, damit es nach den übrigen Rechten gespeichert wird.
                Section::make(__('roles.form.other_permissions'))
                    ->description(__('roles.form.other_permissions_help'))
                    ->schema([
                        DataPermissionMatrix::make('other_data_permissions')->hiddenLabel()->outside()->app(fn (Get $get): mixed => $get('app_id'))
                            ->visible(fn (DataPermissionMatrix $component): bool => $component->tables()->isNotEmpty()),
                        CheckboxList::make('other_permissions')
                            ->hiddenLabel()
                            ->options(fn (Get $get, ?Role $record): array => self::otherPermissionOptions($record, self::appId($get('app_id'))))
                            ->columns(2)
                            ->bulkToggleable()
                            ->dehydrated(false)
                            ->afterStateHydrated(fn (CheckboxList $component, Get $get, ?Role $record): mixed => $component->state(array_map('strval', array_keys(self::otherPermissionOptions($record, self::appId($get('app_id')))))))
                            ->saveRelationshipsUsing(function (CheckboxList $component, Get $get, Role $record): void {
                                $offered = array_keys(self::otherPermissionOptions($record, self::appId($get('app_id'))));
                                $removed = array_diff($offered, array_map('intval', (array) $component->getState()));

                                if ($removed !== []) {
                                    $record->permissions()->detach($removed);
                                }
                            }),
                    ])
                    ->visible(fn (Get $get, ?Role $record): bool => self::hasOutside($record, self::appId($get('app_id')))),
            ]);
    }

    /**
     * Menüeinträge vom Typ Datentabelle, beschriftet mit dem übergeordneten Eintrag („Daten › Kunden“).
     *
     * @return array<int, string>
     */
    private static function dataMenuOptions(?int $appId): array
    {
        // Kein once(): Es unterscheidet nicht zuverlässig nach der App, die im Formular wechseln kann.
        return Menu::query()
            ->where('route', DataMenu::ROUTE)
            ->where('app_id', $appId ?? 0)
            ->with('parent')
            ->get()
            ->sortBy([
                fn (Menu $a, Menu $b): int => [$a->parent?->sort_order ?? -1, mb_strtolower((string) $a->parent?->label)] <=> [$b->parent?->sort_order ?? -1, mb_strtolower((string) $b->parent?->label)],
                fn (Menu $a, Menu $b): int => [$a->sort_order, mb_strtolower((string) $a->label)] <=> [$b->sort_order, mb_strtolower((string) $b->label)],
            ])
            ->mapWithKeys(fn (Menu $menu): array => [
                (int) $menu->id => ($menu->parent ? $menu->parent->label.' › ' : '').$menu->label
                    .($menu->is_active ? '' : ' ('.__('roles.form.inactive_entry').')'),
            ])
            ->all();
    }

    private static function appId(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }

    /** Aktionen der Datenrechte (Spalten der Matrix). */
    /**
     * Allgemeine Rechte (ohne data.*) als id => Bezeichnung, alphabetisch in der aktuellen Sprache.
     *
     * @return array<int, string>
     */
    public static function permissionOptions(?int $appId = null): array
    {
        $options = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'not like', DataPermissions::PREFIX.'%')
            ->whereNotIn('name', self::otherAppPluginPermissions($appId))
            ->pluck('name', 'id')
            ->map(fn (string $name): string => self::label($name))
            ->all();

        $collator = new \Collator(app()->getLocale());
        uasort($options, fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $options;
    }

    /**
     * Rechte von Plugins, die zu einer anderen App gehören (PluginApps); Plugins ohne App gelten überall.
     *
     * @return list<string>
     */
    public static function otherAppPluginPermissions(?int $appId): array
    {
        $apps = app(PluginApps::class);

        return array_merge([], ...array_values(array_map(
            static fn ($plugin): array => $apps->belongsTo($plugin, $appId) ? [] : $plugin->permissionNames(),
            app(PluginRegistry::class)->all(),
        )));
    }

    /** Hat die Rolle Rechte ausserhalb der App (Tabellen anderer Apps, Plugins anderer Apps, gelöschte Tabellen)? */
    private static function hasOutside(?Role $role, ?int $appId): bool
    {
        if ($role === null || ! $role->exists) {
            return false;
        }

        $names = $role->permissions()->where('name', 'like', DataPermissions::PREFIX.'%')->pluck('name')->all();
        $tables = array_values(array_unique(array_map(static fn (string $name): string => Str::beforeLast(Str::after($name, DataPermissions::PREFIX), '.'), $names)));
        $foreign = $tables === [] ? collect() : CustomTable::query()->whereIn('name', $tables)
            ->when($appId !== null, static fn ($q) => $q->where(static fn ($q) => $q->whereNull('app_id')->orWhere('app_id', '!=', $appId)))
            ->get(['name', 'app_id', 'is_shared']);
        // Leserecht auf freigegebene Tabellen anderer Apps steht im eigenen Abschnitt (Schnittstellen), nicht hier.
        $foreignTables = $foreign->contains(static fn (CustomTable $table): bool => ! ($appId !== null && $table->is_shared && $table->app_id !== null)
            || array_diff(array_filter($names, static fn (string $n): bool => str_starts_with($n, DataPermissions::PREFIX.$table->name.'.')), [DataPermissions::PREFIX.$table->name.'.view']) !== []);

        return $foreignTables || self::otherPermissionOptions($role, $appId) !== [];
    }

    /**
     * Rechte der Rolle ausserhalb ihrer App, die nicht in der Matrix stehen, als id => Bezeichnung: Rechte von Plugins
     * anderer Apps und Datenrechte gelöschter Tabellen.
     *
     * @return array<int, string>
     */
    public static function otherPermissionOptions(?Role $role, ?int $appId): array
    {
        if ($role === null || ! $role->exists) {
            return [];
        }

        $existingTables = CustomTable::query()->pluck('name')->all();
        $pluginPermissions = self::otherAppPluginPermissions($appId);
        $options = [];

        foreach ($role->permissions()->get(['permissions.id', 'permissions.name']) as $permission) {
            $name = (string) $permission->name;
            $foreign = str_starts_with($name, DataPermissions::PREFIX)
                ? ! in_array(Str::beforeLast(Str::after($name, DataPermissions::PREFIX), '.'), $existingTables, true)
                : in_array($name, $pluginPermissions, true);

            if ($foreign) {
                $options[(int) $permission->id] = self::label($name).self::appSuffix($name);
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** „ (App Lager)“ bzw. „ (Tabelle gelöscht)“ hinter einem Recht ausserhalb der App. */
    private static function appSuffix(string $permission): string
    {
        if (($plugin = app(PluginRegistry::class)->forPermission($permission)) !== null) {
            $app = App::query()->find(app(PluginApps::class)->appIdFor($plugin));

            return $app === null ? '' : ' ('.$app->name.')';
        }

        $table = CustomTable::query()->where('name', Str::beforeLast(Str::after($permission, DataPermissions::PREFIX), '.'))->first();

        return $table === null ? ' ('.__('roles.form.table_deleted').')' : ' ('.($table->app?->name ?? __('apps.system')).')';
    }

    public static function label(string $permission): string
    {
        if (($plugin = app(PluginRegistry::class)->forPermission($permission)) !== null) {
            return $plugin->label().': '.($plugin->permissions()[Str::after($permission, $plugin->id().'.')] ?? $permission);
        }

        $resource = Str::beforeLast($permission, '.');
        $action = Str::afterLast($permission, '.');

        return self::resourceLabel($resource).': '.(Lang::has($key = "roles.actions.{$action}") ? __($key) : $action);
    }

    /** data.<tabelle> -> "Daten: <Label der Designer-Tabelle>". */
    private static function resourceLabel(string $resource): string
    {
        if (str_starts_with($resource, DataPermissions::PREFIX)) {
            $table = Str::after($resource, DataPermissions::PREFIX);
            $labels = once(static fn (): array => CustomTable::labelOptions());

            return __('roles.data_resource', ['table' => $labels[$table] ?? $table]);
        }

        return in_array($resource, self::RESOURCES, true) ? __('roles.resources.'.str_replace('.', '_', $resource)) : $resource;
    }
}
