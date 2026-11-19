<?php

declare(strict_types=1);

namespace App\DynamicData\Diagram;

use App\DynamicData\DataAccess;
use App\DynamicData\DataMenu;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\ReportLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ermittelt das Datenmodell zu einem Menüeintrag vom Typ „Datenmodell“: alle Datentabellen in
 * dessen Gruppe einschließlich Untergruppen (ein Eintrag auf oberster Ebene: das ganze Menü).
 *
 * Es zählt nur, was der Benutzer auch im Menü und in den Daten sieht: aktive, für ihn sichtbare
 * Menüeinträge, aktive Tabellen mit Leserecht, aktive Felder. Verknüpfungen auf sichtbare
 * Tabellen außerhalb der Gruppe ergeben Randknoten; auf nicht sichtbare entfallen sie.
 */
final class DataModelGraphBuilder
{
    public function build(Menu $entry, User $user): DataModelGraph
    {
        return $this->buildForTables($this->tableNamesBelow($entry->parent_id, $user), $user);
    }

    /**
     * Datenmodell bestimmter Tabellen, z. B. aller Tabellen einer App (B-107); gleiche Regeln wie build().
     *
     * @param  list<string>  $names
     */
    public function buildForTables(array $names, User $user): DataModelGraph
    {
        $tables = $this->readable($names, $user);
        $outside = [];
        $hidden = [];
        $edges = [];

        // Tabellenverknüpfungen wie Verknüpfungsfelder behandeln (Quelle = Kindtabelle), Kennzeichen „link“.
        $links = ReportLink::query()->with(['sourceField', 'targetTable', 'targetField'])->get()->groupBy('source_table_id');

        foreach ($tables as $table) {
            $relations = $table->fields
                ->filter(fn (CustomField $f): bool => $f->type === FieldType::Relation)
                ->map(fn (CustomField $f): array => [$f, (string) ($f->optionsArray()['table'] ?? ''), null]);
            $tableLinks = ($links->get($table->id) ?? collect())
                ->filter(fn (ReportLink $l): bool => $l->sourceField?->is_active === true && $l->targetTable !== null
                    && ($l->target_field_id === null || $l->targetField?->is_active === true))
                ->map(fn (ReportLink $l): array => [$l->sourceField, $l->targetTable->name, $l]);

            foreach ([...$relations->values()->all(), ...$tableLinks->values()->all()] as [$field, $target, $link]) {
                if ($target === '') {
                    continue;
                }

                if (! isset($tables[$target]) && ! isset($outside[$target]) && ! isset($hidden[$target])) {
                    $found = $this->readable([$target], $user)[$target] ?? null;
                    if ($found === null) {
                        $hidden[$target] = true;
                    } else {
                        $outside[$target] = $found;
                    }
                }

                $parent = $tables[$target] ?? $outside[$target] ?? null;

                if ($parent === null) {
                    continue;
                }

                $edges[] = [
                    'from' => $table->name,
                    'to' => $target,
                    'field' => $field,
                    'required' => (bool) $field->is_required,
                    'detail' => in_array($link === null ? $field->id : -$link->id, array_map('intval', (array) ($parent->detail_field_ids ?? [])), true),
                    'link' => $link === null ? null : (string) $link->label,
                ];
            }
        }

        uasort($outside, fn (CustomTable $a, CustomTable $b): int => mb_strtolower((string) $a->label) <=> mb_strtolower((string) $b->label));

        return new DataModelGraph($tables, $outside, $edges);
    }

    /**
     * Zeigt das Datenmodell dem Benutzer mindestens eine Tabelle? Gleiche Auswahl wie build(),
     * aber ohne Felder – für den Menübaum, der leere Datenmodelle (und damit leere Gruppen) ausblendet.
     */
    public function hasTables(Menu $entry, User $user): bool
    {
        $names = $this->tableNamesBelow($entry->parent_id, $user);

        return $names !== [] && CustomTable::query()->active()->whereIn('name', $names)->get()
            ->contains(fn (CustomTable $table): bool => DataAccess::allows($user, $table, 'view'));
    }

    /**
     * Tabellennamen der Datentabellen-Einträge unterhalb einer Gruppe (rekursiv).
     *
     * @return list<string>
     */
    private function tableNamesBelow(?int $groupId, User $user): array
    {
        $byParent = Menu::query()->active()->visibleFor($user)
            ->orderBy('sort_order')
            ->get(['id', 'parent_id', 'route', 'route_parameters'])
            ->groupBy(fn (Menu $menu): string => (string) ($menu->parent_id ?? 'root'));

        $names = [];
        $queue = [$groupId === null ? 'root' : (string) $groupId];
        $seen = [];

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            foreach ($byParent[$key] ?? [] as $menu) {
                if ($menu->route === DataMenu::ROUTE && filled($menu->route_parameters['table'] ?? null)) {
                    $names[] = (string) $menu->route_parameters['table'];
                }

                $queue[] = (string) $menu->id;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Aktive Tabellen mit Leserecht, samt aktiven Feldern.
     *
     * @param  list<string>  $names
     * @return array<string, CustomTable>
     */
    private function readable(array $names, User $user): array
    {
        if ($names === []) {
            return [];
        }

        /** @var Collection<int, CustomTable> $tables */
        $tables = CustomTable::query()
            ->active()
            ->whereIn('name', $names)
            ->with([
                'fields' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'databaseConnection:id,name',
            ])
            ->get();

        return $tables
            ->filter(fn (CustomTable $table): bool => DataAccess::allows($user, $table, 'view'))
            ->sortBy(fn (CustomTable $table): string => mb_strtolower((string) $table->label))
            ->keyBy('name')
            ->all();
    }
}
