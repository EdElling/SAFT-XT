<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Models\CustomTable;

/**
 * Tabellen und Felder, die Plugins brauchen (SaftPlugin::requiredTables()): Prüfung, ob sie vorhanden und aktiv sind,
 * und welche Plugins eine Tabelle oder ein Feld verwenden (Schutz im Table-Designer). Das Ergebnis wird je Anfrage
 * zwischengespeichert und bei jeder Änderung an Tabellen oder Feldern verworfen (AppServiceProvider).
 */
final class PluginDependencies
{
    /** @var array<string, array{active: bool, fields: array<string, bool>}>|null Tabelle => aktiv, Feld => aktiv */
    private ?array $schema = null;

    /** @var array<string, list<string>> je Plugin-id */
    private array $missing = [];

    public function __construct(private readonly PluginRegistry $plugins) {}

    public function flush(): void
    {
        $this->schema = null;
        $this->missing = [];
    }

    /** @return list<string> lesbare Meldungen, was fehlt (leer = alles da) */
    public function missing(SaftPlugin $plugin): array
    {
        if (isset($this->missing[$plugin->id()])) {
            return $this->missing[$plugin->id()];
        }

        $schema = $this->schema();
        $problems = [];

        foreach ($plugin->requiredTables() as $table => $fields) {
            if (! isset($schema[$table])) {
                $problems[] = __('plugins.dependencies.table_missing', ['table' => $table]);

                continue;
            }

            if (! $schema[$table]['active']) {
                $problems[] = __('plugins.dependencies.table_inactive', ['table' => $table]);
            }

            foreach ($fields as $field) {
                if (! isset($schema[$table]['fields'][$field])) {
                    $problems[] = __('plugins.dependencies.field_missing', ['field' => "{$table}.{$field}"]);
                } elseif (! $schema[$table]['fields'][$field]) {
                    $problems[] = __('plugins.dependencies.field_inactive', ['field' => "{$table}.{$field}"]);
                }
            }
        }

        return $this->missing[$plugin->id()] = $problems;
    }

    /**
     * Vollständige Aufstellung für die Anzeige: jede Tabelle mit Bezeichnung und Zustand, darunter jedes benötigte Feld.
     *
     * @return list<array{name: string, label: string|null, state: string, fields: list<array{name: string, label: string|null, state: string}>}>
     *                                                                                                                                            state: ok, missing, inactive
     */
    public function details(SaftPlugin $plugin): array
    {
        $tables = CustomTable::query()->with('fields')->whereIn('name', array_keys($plugin->requiredTables()))->get()->keyBy('name');
        $rows = [];

        foreach ($plugin->requiredTables() as $name => $fields) {
            $table = $tables[$name] ?? null;
            $byName = $table?->fields->keyBy('name');

            $rows[] = [
                'name' => $name,
                'label' => $table === null ? null : (string) $table->label,
                'state' => $table === null ? 'missing' : ($table->is_active ? 'ok' : 'inactive'),
                'fields' => array_map(static function (string $field) use ($byName): array {
                    $column = $byName?->get($field);

                    return [
                        'name' => $field,
                        'label' => $column === null ? null : (string) $column->label,
                        'state' => $column === null ? 'missing' : ($column->is_active ? 'ok' : 'inactive'),
                    ];
                }, $fields),
            ];
        }

        return $rows;
    }

    public function satisfied(SaftPlugin $plugin): bool
    {
        return $this->missing($plugin) === [];
    }

    /**
     * Installierte Plugins, die diese Tabelle (ohne Feld) bzw. dieses Feld brauchen.
     *
     * @return list<SaftPlugin>
     */
    public function usedBy(string $table, ?string $field = null): array
    {
        return array_values(array_filter(
            $this->plugins->all(),
            static fn (SaftPlugin $plugin): bool => array_key_exists($table, $required = $plugin->requiredTables())
                && ($field === null || in_array($field, $required[$table], true)),
        ));
    }

    /** Hinweis für die Oberfläche („Wird vom Plugin … gebraucht“) oder null, wenn kein Plugin die Tabelle/das Feld braucht. */
    public function hint(string $table, ?string $field = null): ?string
    {
        $plugins = $this->usedBy($table, $field);

        return $plugins === [] ? null : __($field === null ? 'tables.plugin_required_table' : 'tables.plugin_required', ['plugins' => $this->names($plugins)]);
    }

    /** Namen der Plugins für Meldungen, z. B. „Lager-Umbuchung“. */
    public function names(array $plugins): string
    {
        return implode(', ', array_map(static fn (SaftPlugin $plugin): string => $plugin->label(), $plugins));
    }

    /** @return array<string, array{active: bool, fields: array<string, bool>}> */
    private function schema(): array
    {
        return $this->schema ??= CustomTable::query()->with('fields')->get()
            ->mapWithKeys(static fn (CustomTable $table): array => [$table->name => [
                'active' => (bool) $table->is_active,
                'fields' => $table->fields->mapWithKeys(static fn ($field): array => [$field->name => (bool) $field->is_active])->all(),
            ]])
            ->all();
    }
}
