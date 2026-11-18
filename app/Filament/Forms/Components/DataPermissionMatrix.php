<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Models\CustomTable;
use App\Models\Role;
use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

/**
 * Datenrechte einer Rolle als kompakte Matrix: eine Zeile je Tabelle, eine Spalte je Recht, dazu
 * „Alle“ je Zeile und je Spalte. Reiter „Eigene Tabellen“ und „Verknüpfte Tabellen“ (nach
 * Datenbankverbindung gruppiert), Suchfeld. Zustand: Tabellenname => angehakte Rechte.
 * Gespeichert werden nur die angebotenen data.<tabelle>.*-Rechte; alle übrigen Rechte bleiben.
 */
class DataPermissionMatrix extends Field
{
    public const array ACTIONS = ['view', 'create', 'update', 'delete', 'export', 'import'];

    /** Angebundene externe Tabellen: Schreibrechte nur, wenn für die Tabelle freigeschaltet. */
    public const array EXTERNAL_ACTIONS = ['view', 'export'];

    protected string $view = 'filament.forms.components.data-permission-matrix';

    /** @var Collection<int, CustomTable>|null */
    private ?Collection $tables = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
        $this->dehydrated(false);

        $this->loadStateFromRelationshipsUsing(function (DataPermissionMatrix $component, ?Role $record): void {
            $granted = $record?->permissions()->where('name', 'like', DataPermissions::PREFIX.'%')->pluck('name')->all() ?? [];
            $state = [];

            foreach ($component->tables() as $table) {
                $state[$table->name] = array_values(array_filter(
                    self::ACTIONS,
                    static fn (string $action): bool => in_array(DataPermissions::name($table->name, $action), $granted, true),
                ));
            }

            $component->state($state);
        });

        $this->saveRelationshipsUsing(function (DataPermissionMatrix $component, Role $record): void {
            $state = (array) $component->getState();

            foreach ($component->tables() as $table) {
                self::sync($record, $table->name, array_values((array) ($state[$table->name] ?? [])), $component->actionsFor($table));
            }
        });
    }

    /** App, deren Tabellen die Matrix zeigt (B-107); leer = alle (Systemrollen). */
    private Closure|int|null $app = null;

    public function app(Closure|int|null $app): static
    {
        $this->app = $app;

        return $this;
    }

    /** @return Collection<int, CustomTable> */
    public function tables(): Collection
    {
        $appId = $this->evaluate($this->app);

        return $this->tables ??= CustomTable::query()
            ->when(filled($appId), static fn ($q) => $q->where('app_id', (int) $appId))
            ->with('databaseConnection:id,name')
            ->get()
            ->sortBy(fn (CustomTable $table): string => mb_strtolower((string) $table->label))
            ->values();
    }

    /** @return list<string> Rechte, die die Tabelle unterstützt */
    public function actionsFor(CustomTable $table): array
    {
        return $table->isExternal()
            ? array_values(array_filter(self::ACTIONS, static fn (string $action): bool => in_array($action, self::EXTERNAL_ACTIONS, true) || $table->supports($action)))
            : self::ACTIONS;
    }

    /**
     * Zeilen für die Ansicht, gruppiert: Reiter (own|external) => Gruppe (Verbindung oder '') => Zeilen.
     *
     * @return array<string, array<string, list<array{name: string, label: string, actions: list<string>, inactive: bool}>>>
     */
    public function groups(): array
    {
        $groups = ['own' => [], 'external' => []];
        $menuGroups = DataMenu::groupPaths();

        foreach ($this->tables() as $table) {
            $tab = $table->isExternal() ? 'external' : 'own';
            // Eigene Tabellen nach Menügruppe (gleich benannte, z. B. „Artikel“ in Shop und Lager, bleiben
            // unterscheidbar), verknüpfte nach Datenbankverbindung.
            $group = $table->isExternal()
                ? (string) ($table->databaseConnection?->name ?? '–')
                : ($menuGroups[$table->name] ?? __('roles.matrix.no_menu'));
            $groups[$tab][$group][] = [
                'name' => $table->name,
                'label' => (string) $table->label,
                'actions' => $this->actionsFor($table),
                'inactive' => ! $table->is_active,
            ];
        }

        foreach (['own', 'external'] as $tab) {
            uksort($groups[$tab], fn (string $a, string $b): int => strnatcasecmp($a, $b));
        }

        return $groups;
    }

    /** Nur angebotene Rechte je Tabelle sind gültig (z. B. keine Schreibrechte für nur lesbare). */
    public function dehydrateValidationRules(array &$rules): void
    {
        parent::dehydrateValidationRules($rules);

        $statePath = $this->getStatePath();

        foreach ($this->tables() as $table) {
            $rules["{$statePath}.{$table->name}"] = ['nullable', 'array'];
            $rules["{$statePath}.{$table->name}.*"] = [Rule::in($this->actionsFor($table))];
        }
    }

    /**
     * @param  list<string>  $actions  angehakte Rechte
     * @param  list<string>  $offered  angebotene Rechte; nur diese werden geändert
     */
    private static function sync(Role $role, string $tableName, array $actions, array $offered): void
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', array_map(static fn (string $action): string => DataPermissions::name($tableName, $action), $offered))
            ->pluck('id', 'name');

        $wanted = $permissions->only(array_map(
            static fn (string $action): string => DataPermissions::name($tableName, $action),
            array_intersect($actions, $offered),
        ));

        $role->permissions()->detach($permissions->except($wanted->keys()->all())->values()->all());
        $role->permissions()->syncWithoutDetaching($wanted->values()->all());
    }
}
