<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Filament\Forms\Components\DataPermissionMatrix;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Role;
use App\Plugins\PluginRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
    public const array RESOURCES = ['users', 'roles', 'menus', 'activity_log', 'identity_providers', 'settings.auth', 'settings.app', 'widgets', 'custom_tables', 'database_connections', 'table_connector', 'reports', 'report_links', 'tasks', 'plugins', 'configuration', 'help'];

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
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('guard_name', 'web')
                                    ->where('name', 'not like', DataPermissions::PREFIX.'%')
                                    ->orderBy('name'),
                            )
                            // Nach der angezeigten Bezeichnung sortiert (nicht nach dem technischen Namen):
                            // die Rechte eines Bereichs stehen beieinander, spaltenweise von oben nach unten.
                            ->options(fn (): array => self::permissionOptions())
                            ->columns(4)
                            ->bulkToggleable()
                            ->searchable(),
                    ]),
                Section::make(__('roles.form.data_permissions'))
                    ->description(__('roles.form.data_permissions_help'))
                    ->schema([DataPermissionMatrix::make('data_permissions')->hiddenLabel()])
                    ->visible(fn (): bool => CustomTable::query()->exists()),
                Section::make(__('roles.form.menu_entries'))
                    ->description(__('roles.form.menu_entries_help'))
                    ->schema([
                        CheckboxList::make('visible_menus')
                            ->hiddenLabel()
                            ->options(fn (): array => self::dataMenuOptions())
                            ->columns(3)
                            ->gridDirection('row')
                            ->bulkToggleable()
                            ->dehydrated(false)
                            // Gespeichert wird nur das Ausblenden: Ohne Datensatz ist alles angehakt.
                            ->default(fn (): array => array_map('strval', array_keys(self::dataMenuOptions())))
                            ->loadStateFromRelationshipsUsing(function (CheckboxList $component, ?Role $record): void {
                                $hidden = $record?->hiddenMenus()->pluck('menus.id')->all() ?? [];
                                $component->state(array_values(array_map('strval', array_diff(array_keys(self::dataMenuOptions()), $hidden))));
                            })
                            ->saveRelationshipsUsing(function (CheckboxList $component, Role $record): void {
                                $visible = array_map('intval', (array) $component->getState());
                                $record->hiddenMenus()->sync(array_values(array_diff(array_keys(self::dataMenuOptions()), $visible)));
                            }),
                    ])
                    ->visible(fn (): bool => self::dataMenuOptions() !== []),
            ]);
    }

    /**
     * Menüeinträge vom Typ Datentabelle, beschriftet mit dem übergeordneten Eintrag („Daten › Kunden“).
     *
     * @return array<int, string>
     */
    private static function dataMenuOptions(): array
    {
        return once(static fn (): array => Menu::query()
            ->where('route', DataMenu::ROUTE)
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
            ->all());
    }

    /** Aktionen der Datenrechte (Spalten der Matrix). */
    /**
     * Allgemeine Rechte (ohne data.*) als id => Bezeichnung, alphabetisch in der aktuellen Sprache.
     *
     * @return array<int, string>
     */
    public static function permissionOptions(): array
    {
        $options = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'not like', DataPermissions::PREFIX.'%')
            ->pluck('name', 'id')
            ->map(fn (string $name): string => self::label($name))
            ->all();

        $collator = new \Collator(app()->getLocale());
        uasort($options, fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $options;
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
