<?php

declare(strict_types=1);

namespace App\Reports\Editor;

use App\Apps\AppInterfaces;
use App\Apps\CurrentApp;
use App\DynamicData\DataAccess;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\SystemFields;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DataSnapshot;
use App\Models\ReportLink;
use App\Models\User;
use App\Reports\DefinitionResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Felder, die eine Person für eine Grundtabelle wählen kann: die eigenen und – entlang der
 * Verknüpfungsfelder – die der übergeordneten Tabellen (höchstens DefinitionResolver::MAX_DEPTH
 * Stufen) bzw. entlang der Verknüpfungen für Auswertungen, jeweils nur Tabellen mit Leserecht;
 * angebundene Tabellen nur mit Datenstand. Freigegebene Tabellen anderer Apps (Schnittstellen, B-107 Etappe 5b) nur mit
 * ihren freigegebenen Feldern – ohne eigenes Leserecht – und ohne weiterzugehen.
 *
 * Ein Feld wird als Text referenziert: "57/63:31" = Pfad über die Verknüpfungsfelder 57 und 63
 * zum Feld 31; ":31" = Feld der Grundtabelle. Systemspalten (angelegt/geändert am und von, B-183) stehen mit ihrem
 * Namen statt einer Nummer: ":created_at", "57:updated_by" – nur an Designer-Tabellen der eigenen App.
 */
final class FieldCatalog
{
    /** @var Collection<int, CustomTable>|null */
    private ?Collection $tables = null;

    public const string KIND_INTERNAL = 'internal';

    public const string KIND_EXTERNAL = 'external';

    public const string KIND_SNAPSHOT = 'snapshot';

    /**
     * Grundtabellen, die die Person lesen darf (ID => Bezeichnung) – auch angebundene ohne
     * Datenstand; den erzeugt der Editor beim Auswählen.
     *
     * @return array<int, string>
     */
    public function baseTables(?User $user): array
    {
        return collect($this->baseTableOptions($user))->mapWithKeys(fn (array $o): array => [$o['id'] => $o['label']])->all();
    }

    /**
     * Grundtabellen mit Art (SAFT-Tabelle, angebunden ohne bzw. mit Datenstand) für die Auswahlliste.
     *
     * @return list<array{id: int, label: string, name: string, kind: string, snapshot_at: CarbonInterface|null, connection: string|null}>
     */
    public function baseTableOptions(?User $user): array
    {
        // Grundtabellen nur aus der aktuellen App (B-107).
        $appId = $user === null ? null : app(CurrentApp::class)->id($user);

        return $this->tables()
            ->filter(fn (CustomTable $t): bool => $this->listed($t, $user) && ($user === null || $t->app_id === $appId))
            ->sortBy(fn (CustomTable $t): string => mb_strtolower((string) $t->label))
            ->map(fn (CustomTable $t): array => [
                'id' => $t->id,
                'label' => (string) $t->label,
                'name' => $t->name,
                'kind' => ! $t->isExternal() ? self::KIND_INTERNAL : ($this->snapshotAt($t) === null ? self::KIND_EXTERNAL : self::KIND_SNAPSHOT),
                'snapshot_at' => $this->snapshotAt($t),
                'connection' => $t->isExternal() ? $t->databaseConnection?->name : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Angebundene Tabellen, die eine Auswertung über diese Grundtabelle braucht: sie selbst und alle über
     * Verknüpfungen erreichbaren (bis MAX_DEPTH), soweit die Person sie lesen darf – mit oder ohne Stand.
     *
     * @return list<CustomTable>
     */
    public function externalTablesFor(CustomTable $base, ?User $user): array
    {
        $found = [];
        $walk = function (CustomTable $table, int $depth) use (&$walk, &$found, $user): void {
            if (! $this->listed($table, $user) || isset($found[$table->id])) {
                return;
            }

            if ($table->isExternal()) {
                $found[$table->id] = $table;
            }

            if ($depth >= DefinitionResolver::MAX_DEPTH) {
                return;
            }

            foreach ($table->fields as $field) {
                $target = $field->is_active && $field->type === FieldType::Relation
                    ? $this->tables()->firstWhere('name', (string) ($field->optionsArray()['table'] ?? ''))
                    : null;

                if ($target !== null && $target->is_active) {
                    $walk($target, $depth + 1);
                }
            }

            foreach ($this->linksFrom($table) as $link) {
                $target = $this->tables()->get($link->target_table_id);

                if ($target !== null && $target->is_active) {
                    $walk($target, $depth + 1);
                }
            }
        };
        $walk($this->tables()->get($base->id) ?? $base, 0);

        return array_values($found);
    }

    /**
     * Tabellen, die mit der Grundtabelle über Verknüpfungen verbunden sind – in beide Richtungen und über
     * beliebig viele Stufen (der zusammenhängende Teil des Datenmodells), nur aktive, lesbare Tabellen.
     * Nur zwischen ihnen darf im Editor gewechselt werden; Etappe 3b ergänzt hier Verknüpfungen zwischen Quellen.
     *
     * @return list<int>
     */
    public function reachableFrom(int $base, ?User $user): array
    {
        $tables = $this->tables()->filter(fn (CustomTable $t): bool => $this->listed($t, $user));

        if (! $tables->has($base)) {
            return [];
        }

        // Wechsel der Grundtabelle nur innerhalb der App (Schnittstellen sind keine Grundtabellen).
        $appId = $tables->get($base)->app_id;
        $tables = $tables->reject(fn (CustomTable $t): bool => AppInterfaces::foreign($t, $appId));

        $byName = $tables->keyBy('name');
        $neighbours = [];

        foreach ($tables as $table) {
            foreach ($table->fields as $field) {
                $target = $field->is_active && $field->type === FieldType::Relation
                    ? $byName->get((string) ($field->optionsArray()['table'] ?? ''))
                    : null;

                if ($target !== null) {
                    $neighbours[$table->id][$target->id] = true;
                    $neighbours[$target->id][$table->id] = true;
                }
            }

            foreach ($this->linksFrom($table) as $link) {
                if ($tables->has($link->target_table_id)) {
                    $neighbours[$table->id][$link->target_table_id] = true;
                    $neighbours[$link->target_table_id][$table->id] = true;
                }
            }
        }

        $seen = [$base => true];
        $queue = [$base];

        while ($queue !== []) {
            foreach (array_keys($neighbours[array_shift($queue)] ?? []) as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return array_keys($seen);
    }

    /** Zeitpunkt des neuesten vollständigen Datenstands (nur angebundene Tabellen). */
    public function snapshotAt(CustomTable $table): ?CarbonInterface
    {
        return $this->snapshotTimes()[$table->id] ?? null;
    }

    /** Nach dem Erzeugen von Datenständen neu laden. */
    public function forgetSnapshots(): void
    {
        $this->snapshotTimes = null;
    }

    /**
     * Wählbare Felder, gruppiert nach Pfad („Bestellung › Kunde“) für Auswahlfelder.
     *
     * @param  (callable(CustomField): bool)|null  $only  z. B. nur Zahlen
     * @return array<string, array<string, string>>
     */
    public function options(?int $base, ?User $user, ?callable $only = null): array
    {
        $groups = [];

        foreach ($this->entries($base, $user) as $entry) {
            if ($only === null || $only($entry['field'])) {
                $groups[$entry['group']][$entry['ref']] = $entry['label'];
            }
        }

        return $groups;
    }

    /**
     * @return list<array{ref: string, path: list<int>, field: CustomField, group: string, label: string}>
     */
    public function entries(?int $base, ?User $user): array
    {
        $table = $base === null ? null : $this->tables()->get($base);

        if ($table === null || ! $this->usable($table, $user)) {
            return [];
        }

        $entries = [];
        $appId = $table->app_id;
        $walk = function (CustomTable $table, array $path, array $labels) use (&$walk, &$entries, $user, $appId): void {
            $group = $labels === [] ? (string) $table->label : implode(' › ', $labels);
            $interface = AppInterfaces::foreign($table, $appId);

            foreach ($table->fields as $field) {
                // Dateien lassen sich nicht auswerten (B-021); hinter einer Schnittstelle nur freigegebene Felder.
                if (! $field->is_active || $field->type === FieldType::File || ($interface && ! AppInterfaces::fieldShared($table, $field->name))) {
                    continue;
                }

                $entries[] = [
                    'ref' => self::ref($path, $field->id),
                    'path' => $path,
                    'field' => $field,
                    'group' => $group,
                    'label' => $labels === [] ? (string) $field->label : $group.' › '.$field->label,
                ];

                if (! $interface && $field->type === FieldType::Relation && count($path) < DefinitionResolver::MAX_DEPTH) {
                    $target = $this->tables()->firstWhere('name', (string) ($field->optionsArray()['table'] ?? ''));

                    if ($target !== null && $this->reachable($target, $user, $appId)) {
                        $walk($target, [...$path, $field->id], [...$labels, (string) $field->label]);
                    }
                }
            }

            // Systemspalten am Ende der Felder; nicht hinter einer Schnittstelle (dort nur freigegebene Felder).
            foreach ($interface ? [] : SystemFields::for($table) as $field) {
                $entries[] = [
                    'ref' => self::ref($path, $field->name),
                    'path' => $path,
                    'field' => $field,
                    'group' => $group,
                    'label' => $labels === [] ? (string) $field->label : $group.' › '.$field->label,
                ];
            }

            // Verknüpfungen für Auswertungen ab dieser Tabelle (im Pfad als negative Nummer).
            if (! $interface && count($path) < DefinitionResolver::MAX_DEPTH) {
                foreach ($this->linksFrom($table) as $link) {
                    $target = $this->tables()->get($link->target_table_id);

                    if ($target !== null && $this->reachable($target, $user, $appId)) {
                        $walk($target, [...$path, -$link->id], [...$labels, (string) $link->label]);
                    }
                }
            }
        };
        $walk($table, [], []);

        return $entries;
    }

    /** Feld zu einer Referenz (oder null, wenn sie nicht mehr wählbar ist). */
    public function field(?int $base, ?User $user, string $ref): ?CustomField
    {
        foreach ($this->entries($base, $user) as $entry) {
            if ($entry['ref'] === $ref) {
                return $entry['field'];
            }
        }

        return null;
    }

    /**
     * Datenmodell für den KI-Assistenten: alle Tabellen, die die Person auswerten kann (Leserecht; angebundene nur mit
     * Datenstand), mit ihren aktiven Feldern, Verknüpfungsfeldern und Tabellenverknüpfungen. Nur Struktur, keine
     * Datensätze; Auswahlwerte nur mit $withChoices.
     *
     * @return list<array<string, mixed>>
     */
    public function structure(?User $user, bool $withChoices): array
    {
        // Nur Tabellen der aktuellen App (B-107, wie die Grundtabellen im Editor); dazu Schnittstellen (unten).
        $appId = $user === null ? null : app(CurrentApp::class)->id($user);
        $tables = $this->tables()->filter(fn (CustomTable $t): bool => $t->is_active && $this->usable($t, $user)
            && ($user === null || $t->app_id === null || $t->app_id === $appId));
        $locale = app()->getLocale();

        // Schnittstellen (B-107, Etappe 5b): freigegebene Tabellen anderer Apps, auf die eine Verknüpfung oder
        // Tabellenverknüpfung zeigt – nur mit freigegebenen Feldern, ohne eigenes Leserecht, ohne Weitergehen.
        $interfaces = new Collection;

        foreach ($tables as $table) {
            $targets = [
                ...$table->fields->filter(fn (CustomField $f): bool => $f->is_active && $f->type === FieldType::Relation)
                    ->map(fn (CustomField $f): ?CustomTable => $this->tables()->firstWhere('name', (string) ($f->optionsArray()['table'] ?? '')))->all(),
                ...$this->linksFrom($table)->map(fn (ReportLink $l): ?CustomTable => $this->tables()->get($l->target_table_id))->all(),
            ];

            foreach ($targets as $target) {
                if ($target !== null && AppInterfaces::foreign($target, $table->app_id) && $this->reachable($target, $user, $table->app_id)) {
                    $interfaces->put($target->id, $target);
                }
            }
        }

        $all = $tables->union($interfaces);

        return $all->sortBy(fn (CustomTable $t): string => mb_strtolower((string) $t->label))->map(function (CustomTable $table) use ($tables, $all, $withChoices, $locale): array {
            $fields = [];
            $interface = ! $tables->has($table->id);

            foreach ($table->fields as $field) {
                // Dateien lassen sich nicht auswerten (B-021); Schnittstellen nur mit freigegebenen Feldern, ohne Verknüpfungen.
                if (! $field->is_active || $field->type === FieldType::File
                    || ($interface && (! AppInterfaces::fieldShared($table, $field->name) || $field->type === FieldType::Relation))) {
                    continue;
                }

                $entry = ['id' => $field->id, 'label' => (string) $field->label];

                if ($field->type === FieldType::Relation) {
                    $target = $this->tables()->firstWhere('name', (string) ($field->optionsArray()['table'] ?? ''));
                    $entry['type'] = 'relation';

                    if ($target !== null && $all->has($target->id)) {
                        $entry['to'] = $target->id;
                        // Was das Verknüpfungsfeld in einer Auswertung anzeigt (Anzeigefelder des verknüpften Datensatzes).
                        $shows = array_values(array_filter(array_map(
                            fn (string $name): ?string => $target->fields->first(fn (CustomField $f): bool => $f->name === $name && $f->is_active)?->label,
                            RelationDisplay::fields($field->optionsArray()),
                        )));

                        if ($shows !== []) {
                            $entry['shows'] = $shows;
                        }
                    }
                } else {
                    $entry['type'] = DefinitionResolver::kindOf($field->valueType());
                }

                if ($withChoices && $field->type === FieldType::Select) {
                    $entry['choices'] = array_map(static fn (array $o): array => [
                        'value' => (string) ($o['value'] ?? ''),
                        'label' => is_array($o['label'] ?? null) ? (string) ($o['label'][$locale] ?? reset($o['label']) ?: $o['value']) : (string) ($o['label'] ?? $o['value'] ?? ''),
                    ], array_values(array_filter((array) ($field->optionsArray()['options'] ?? []), 'is_array')));
                }

                $fields[] = $entry;
            }

            // Systemspalten (B-183) mit ihrem Namen als id; Namen von Benutzern gehen nicht an das Modell.
            foreach ($interface ? [] : SystemFields::for($table) as $field) {
                $fields[] = ['id' => $field->name, 'label' => (string) $field->label, 'type' => DefinitionResolver::kindOfField($field)];
            }

            $links = ($interface ? new Collection : $this->linksFrom($table))
                ->filter(fn (ReportLink $link): bool => $all->has($link->target_table_id))
                ->map(fn (ReportLink $link): array => ['id' => -$link->id, 'label' => (string) $link->label, 'to' => $link->target_table_id])
                ->values()
                ->all();

            return array_filter([
                'id' => $table->id,
                'label' => (string) $table->label,
                'description' => filled($table->description) ? (string) $table->description : null,
                'fields' => $fields,
                'links' => $links === [] ? null : $links,
                // Nur über eine Verknüpfung erreichbar, keine Grundtabelle; nur diese Felder, nicht weiter.
                'interface' => $interface ? true : null,
            ], static fn (mixed $v): bool => $v !== null);
        })->values()->all();
    }

    /**
     * @param  list<int>  $path
     * @param  int|string  $field  Feld-ID oder Name einer Systemspalte
     */
    public static function ref(array $path, int|string $field): string
    {
        return implode('/', $path).':'.$field;
    }

    /** @return array{path: list<int>, field: int, system: string|null} system = Name einer Systemspalte (dann field 0) */
    public static function parse(string $ref): array
    {
        [$path, $field] = array_pad(explode(':', $ref, 2), 2, '0');
        $system = in_array($field, [...SystemFields::DATES, ...SystemFields::PERSONS], true) ? $field : null;

        return ['path' => $path === '' ? [] : array_map('intval', explode('/', $path)), 'field' => $system === null ? (int) $field : 0, 'system' => $system];
    }

    /** In der Auswahl: aktiv und lesbar. */
    private function listed(CustomTable $table, ?User $user): bool
    {
        return $table->is_active && DataAccess::allows($user, $table, 'view');
    }

    /**
     * Darf der Weg von einer Tabelle der App $appId in diese Tabelle führen? In der eigenen App mit Leserecht, in eine andere
     * App nur in eine freigegebene Tabelle (Schnittstelle) einer aktiven App – dafür ohne eigenes Leserecht.
     */
    private function reachable(CustomTable $table, ?User $user, ?int $appId): bool
    {
        if (! AppInterfaces::foreign($table, $appId)) {
            return $this->usable($table, $user);
        }

        return $table->is_active && $table->is_shared && ! AppInterfaces::unavailable($table, $user)
            && (! $table->isExternal() || $this->snapshotAt($table) !== null);
    }

    /** Für Felder und Abfragen: zusätzlich bei angebundenen Tabellen ein Datenstand. */
    private function usable(CustomTable $table, ?User $user): bool
    {
        return (! $table->isExternal() || $this->snapshotAt($table) !== null) && DataAccess::allows($user, $table, 'view');
    }

    /** @var Collection<int, Collection<int, ReportLink>>|null Verknüpfungen für Auswertungen je Quelltabelle */
    private ?Collection $links = null;

    /**
     * Gültige Verknüpfungen für Auswertungen ab einer Tabelle (Quellfeld aktiv, Zielfeld aktiv bzw. id).
     *
     * @return Collection<int, ReportLink>
     */
    private function linksFrom(CustomTable $table): Collection
    {
        $this->links ??= ReportLink::query()->orderBy('id')->get()
            ->filter(function (ReportLink $link): bool {
                $source = $this->tables()->get($link->source_table_id)?->fields->firstWhere('id', $link->source_field_id);
                $target = $this->tables()->get($link->target_table_id);

                return $source?->is_active === true && $target !== null
                    && ($link->target_field_id === null ? ! $target->isExternal() : $target->fields->firstWhere('id', $link->target_field_id)?->is_active === true);
            })
            ->groupBy('source_table_id');

        return $this->links->get($table->id) ?? new Collection;
    }

    /** @var array<int, CarbonInterface>|null neuester vollständiger Stand je Tabelle */
    private ?array $snapshotTimes = null;

    /** @return array<int, CarbonInterface> */
    private function snapshotTimes(): array
    {
        return $this->snapshotTimes ??= DataSnapshot::query()->where('status', DataSnapshot::COMPLETE)
            ->get(['custom_table_id', 'finished_at'])
            ->groupBy('custom_table_id')
            ->map(fn ($snapshots) => $snapshots->max('finished_at'))
            ->filter()
            ->all();
    }

    /** @return Collection<int, CustomTable> */
    private function tables(): Collection
    {
        return $this->tables ??= CustomTable::query()
            ->with(['fields' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'databaseConnection'])
            ->get()
            ->keyBy('id');
    }
}
