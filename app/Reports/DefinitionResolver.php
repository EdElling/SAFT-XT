<?php

declare(strict_types=1);

namespace App\Reports;

use App\Apps\AppInterfaces;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Schema\SystemFields;
use App\ExternalData\Snapshots\SnapshotService;
use App\ExternalData\TableConnectorService;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DataSnapshot;
use App\Models\ReportLink;
use Illuminate\Support\Collection;

/**
 * Prüft eine Auswertungs-Definition gegen das aktuelle Datenmodell und löst sie auf:
 * Tabellen und Felder vorhanden und aktiv, Pfade nur über Verknüpfungsfelder zur
 * übergeordneten Tabelle (so vervielfachen sich keine Zeilen), Aggregate und Filter passend
 * zum Feldtyp. Sammelt alle Fehler und wirft sie gemeinsam.
 *
 * Pfade führen über Verknüpfungsfelder oder über Verknüpfungen für Auswertungen (negative Nummer im
 * Pfad = report_links.id), letztere auch zwischen SAFT-Tabellen und angebundenen Tabellen.
 *
 * Angebundene externe Tabellen werden nie live abgefragt, sondern über ihren neuesten vollständigen
 * Datenstand (Kopie in SAFTs Datenbank); ohne Datenstand ist die Auswertung nicht ausführbar.
 *
 * Über die App-Grenze (B-107, Etappe 5b) führt ein Pfad nur in eine freigegebene Tabelle einer anderen App (Schnittstelle),
 * dort nur zu freigegebenen Feldern und nicht weiter.
 */
final class DefinitionResolver
{
    public const int MAX_DEPTH = 3;

    public const int MAX_FIELDS = 50;

    public const int MAX_FILTERS = 20;

    public const int MAX_COLUMNS = 1;

    /** Schlüssel werden Spaltenaliase der Abfrage: nur einfache Bezeichner. */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/';

    /** Operatoren je Wertetyp (leer/nicht leer gibt es für alle). */
    public const array OPERATORS = [
        'text' => ['eq', 'neq', 'contains', 'starts_with', 'in'],
        'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'in'],
        'date' => ['eq', 'gt', 'gte', 'lt', 'lte', 'between', 'today', 'this_month', 'last_month', 'this_year', 'last_year', 'last_days'],
        'boolean' => ['is_true', 'is_false'],
        'relation' => ['eq', 'neq', 'in'],
        // Angelegt/geändert von (B-183): bestimmte Benutzer oder die Person, die die Auswertung ausführt.
        'person' => ['eq', 'neq', 'in', 'is_me'],
    ];

    public const array ANY_OPERATORS = ['is_empty', 'not_empty'];

    /** @var list<string> */
    private array $errors = [];

    /** @var Collection<int, CustomTable>|null Datenmodell, einmal je Instanz geladen (Liste prüft viele Auswertungen) */
    private ?Collection $tables = null;

    /** @var Collection<int, ReportLink>|null Verknüpfungen für Auswertungen, einmal je Instanz geladen */
    private ?Collection $links = null;

    /** @var array<int, DataSnapshot> Datenstände der laufenden Auflösung */
    private array $snapshots = [];

    /** @var array<int, list<string>> Spalten der Datenstand-Kopien */
    private array $snapshotColumns = [];

    /** App der Grundtabelle der laufenden Auflösung */
    private ?int $baseApp = null;

    /** @var array<int, true> über eine Schnittstelle erreichte Tabellen der laufenden Auflösung */
    private array $interfaces = [];

    public function resolve(ReportDefinition $definition): ResolvedReport
    {
        $this->errors = [];
        $this->snapshots = [];
        $this->interfaces = [];
        $this->tables ??= CustomTable::query()->with(['fields' => fn ($q) => $q->orderBy('sort_order')])->get()->keyBy('id');
        $base = $this->usableTable($this->tables->get($definition->base), '#'.$definition->base);

        if ($base === null) {
            throw new InvalidReportException($this->errors);
        }

        $this->baseApp = $base->app_id;

        $fields = [];
        $joins = [];
        $involved = [$base->id => $base];

        foreach (array_slice($definition->fields, 0, self::MAX_FIELDS) as $spec) {
            if (preg_match(self::KEY_PATTERN, $spec['key']) !== 1 || isset($fields[$spec['key']])) {
                $this->error('duplicate_key', ['key' => $spec['key']]);

                continue;
            }

            $resolved = $this->resolveField($spec, $base, $joins, $involved);

            if ($resolved !== null) {
                $fields[$spec['key']] = $resolved;
            }
        }

        if (count($definition->fields) > self::MAX_FIELDS) {
            $this->error('too_many_fields', ['max' => self::MAX_FIELDS]);
        }

        $this->attachDisplays($definition, $fields, $joins);
        $this->checkStructure($definition, $fields);
        $this->checkMeasures($definition, $fields);
        $this->checkCalculatedMeasures($definition, $fields);
        $this->checkExpressions($definition, $fields);
        $this->checkFilters($definition, $fields);
        $this->checkSort($definition, $fields);
        $this->checkVisual($definition);

        if ($this->errors !== []) {
            throw new InvalidReportException(array_values(array_unique($this->errors)));
        }

        return new ResolvedReport($definition, $base, $fields, array_values($joins), $involved, $this->snapshots, array_keys($this->interfaces));
    }

    /**
     * @param  array{key: string, path: list<int>, field: int, bucket: string|null, label: string|null, system?: string}  $spec
     * @param  array<string, array{alias: string, parent: string, relation: CustomField, table: CustomTable}>  $joins
     * @param  array<int, CustomTable>  $involved
     */
    private function resolveField(array $spec, CustomTable $base, array &$joins, array &$involved): ?ResolvedField
    {
        if (count($spec['path']) > self::MAX_DEPTH) {
            $this->error('path_too_deep', ['max' => self::MAX_DEPTH]);

            return null;
        }

        $table = $base;
        $alias = 't0';
        $labels = [];
        $path = [];
        $pathKey = '';
        $crossed = false;

        foreach ($spec['path'] as $relationId) {
            // Hinter einer Schnittstelle geht es nicht weiter.
            if ($crossed) {
                $this->error('interface_depth', ['label' => $spec['label'] ?? '#'.$spec['field'], 'table' => $table->label]);

                return null;
            }

            // Negative Nummer: Verknüpfung für Auswertungen (report_links), sonst Verknüpfungsfeld.
            $step = $relationId < 0 ? $this->linkStep(-$relationId, $table, $spec) : $this->relationStep($relationId, $table, $spec);

            if ($step === null) {
                return null;
            }

            [$relation, $target, $key, $label] = $step;

            if (AppInterfaces::foreign($target, $this->baseApp)) {
                if (! $target->is_shared) {
                    $this->error('not_shared_table', ['table' => $target->label]);

                    return null;
                }

                $crossed = true;
                $this->interfaces[$target->id] = true;
            }

            $pathKey .= '/'.$relationId;

            if (! isset($joins[$pathKey])) {
                $joins[$pathKey] = ['alias' => 't'.(count($joins) + 1), 'parent' => $alias, 'relation' => $relation, 'table' => $target, 'key' => $key];
            }

            $alias = $joins[$pathKey]['alias'];
            $labels[] = $label;
            $path[] = $relation;
            $table = $target;
            $involved[$target->id] = $target;
        }

        if (isset($spec['system'])) {
            return $this->resolveSystemField($spec, $table, $path, $alias, $labels, $crossed);
        }

        $field = $table->fields->firstWhere('id', $spec['field']);

        // Dateien lassen sich nicht auswerten (B-021): wie ein fehlendes Feld behandeln.
        if (! $field instanceof CustomField || ! $field->is_active || $field->type === FieldType::File) {
            $this->error('missing_field', ['label' => $spec['label'] ?? '#'.$spec['field'], 'table' => $table->label]);

            return null;
        }

        if ($crossed && ! AppInterfaces::fieldShared($table, $field->name)) {
            $this->error('not_shared_field', ['label' => $field->label, 'table' => $table->label]);

            return null;
        }

        if (isset($this->snapshots[$table->id]) && ! in_array($field->name, $this->snapshotColumns($table), true)) {
            $this->error('not_in_snapshot', ['label' => $field->label, 'table' => $table->label]);

            return null;
        }

        $resolved = new ResolvedField($spec['key'], $field, $table, $path, $alias, $spec['bucket'], $spec['label'] ?? implode(' › ', [...$labels, $field->label]));

        if ($spec['bucket'] !== null && (! in_array($spec['bucket'], ReportDefinition::BUCKETS, true) || ! $resolved->isTemporal())) {
            $this->error('invalid_bucket', ['label' => $resolved->label]);
        }

        return $resolved;
    }

    /**
     * Systemspalte (angelegt/geändert am und von, B-183): nur an Designer-Tabellen der eigenen App – angebundene
     * Tabellen haben die Spalten nicht, hinter einer Schnittstelle gelten nur freigegebene Felder.
     *
     * @param  array{key: string, path: list<int>, field: int, bucket: string|null, label: string|null, system?: string}  $spec
     * @param  list<CustomField>  $path
     * @param  list<string>  $labels
     */
    private function resolveSystemField(array $spec, CustomTable $table, array $path, string $alias, array $labels, bool $crossed): ?ResolvedField
    {
        $field = $crossed ? null : SystemFields::find($table, (string) $spec['system']);

        if ($field === null) {
            $this->error('missing_field', ['label' => $spec['label'] ?? $spec['system'], 'table' => $table->label]);

            return null;
        }

        $resolved = new ResolvedField($spec['key'], $field, $table, $path, $alias, $spec['bucket'], $spec['label'] ?? implode(' › ', [...$labels, $field->label]));

        if ($spec['bucket'] !== null && (! in_array($spec['bucket'], ReportDefinition::BUCKETS, true) || ! $resolved->isTemporal())) {
            $this->error('invalid_bucket', ['label' => $resolved->label]);
        }

        return $resolved;
    }

    /**
     * Verknüpfungsfelder in Zeilen, Spalten oder einer Liste zeigen die Anzeigefelder des verknüpften Datensatzes
     * (wie die Tabellenansicht), gruppiert wird weiter nach dem Schlüssel. Fehlt etwas (kein Anzeigefeld, kein
     * Datenstand, Feld nicht in der Kopie), bleibt es ohne Fehler beim Schlüssel.
     *
     * @param  array<string, ResolvedField>  $fields
     * @param  array<string, array{alias: string, parent: string, relation: CustomField, table: CustomTable, key: string}>  $joins
     */
    private function attachDisplays(ReportDefinition $definition, array &$fields, array &$joins): void
    {
        $keys = $definition->isGrouped() ? [...$definition->groups, ...$definition->columns] : $definition->shownKeys();

        foreach (array_unique($keys) as $key) {
            $resolved = $fields[$key] ?? null;

            // Verknüpfungsfelder hinter einer Schnittstelle bleiben beim Schlüssel (nicht weiter in die fremde App).
            if ($resolved === null || $resolved->bucket !== null || $resolved->field->type !== FieldType::Relation
                || AppInterfaces::foreign($resolved->table, $this->baseApp)) {
                continue;
            }

            $options = $resolved->field->optionsArray();
            $target = $this->tables->firstWhere('name', (string) ($options['table'] ?? ''));
            $targetKey = $target?->keyColumn();

            if ($target === null || ! $target->is_active || $targetKey === null) {
                continue;
            }

            if ($target->isExternal()) {
                $snapshot = $this->snapshots[$target->id] ?? $target->latestSnapshot();

                if ($snapshot === null) {
                    continue;
                }

                $this->snapshots[$target->id] = $snapshot;
            }

            $columns = array_values(array_filter(
                RelationDisplay::fields($options),
                fn (string $name): bool => $target->fields->first(fn (CustomField $f): bool => $f->name === $name && $f->is_active) !== null
                    && (! $target->isExternal() || in_array($name, $this->snapshotColumns($target), true)),
            ));

            if ($columns === []) {
                continue;
            }

            $pathKey = implode('', array_map(fn (int $id): string => '/'.$id, $definition->field($key)['path'] ?? [])).'/'.$resolved->field->id;
            $joins[$pathKey] ??= ['alias' => 't'.(count($joins) + 1), 'parent' => $resolved->alias, 'relation' => $resolved->field, 'table' => $target, 'key' => $targetKey];
            $fields[$key] = $resolved->withDisplay(['alias' => $joins[$pathKey]['alias'], 'columns' => $columns, 'separator' => RelationDisplay::separator($options)]);
        }
    }

    /**
     * Schritt über ein Verknüpfungsfeld.
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: CustomField, 1: CustomTable, 2: string, 3: string}|null Feld, Zieltabelle, Zielspalte, Bezeichnung
     */
    private function relationStep(int $relationId, CustomTable $table, array $spec): ?array
    {
        $relation = $table->fields->firstWhere('id', $relationId);

        if (! $relation instanceof CustomField || $relation->type !== FieldType::Relation || ! $relation->is_active) {
            $this->error('missing_relation', ['label' => $spec['label'] ?? '#'.$relationId, 'table' => $table->label]);

            return null;
        }

        $target = $this->usableTable($this->tables->firstWhere('name', (string) ($relation->optionsArray()['table'] ?? '')), $relation->label);

        if ($target === null) {
            return null;
        }

        $key = $target->keyColumn();

        if ($key === null) {
            $this->error('composite_key', ['label' => $relation->label, 'table' => $target->label]);

            return null;
        }

        return [$relation, $target, $key, (string) $relation->label];
    }

    /**
     * Schritt über eine Verknüpfung für Auswertungen (auch zwischen Quellen).
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: CustomField, 1: CustomTable, 2: string, 3: string}|null
     */
    private function linkStep(int $linkId, CustomTable $table, array $spec): ?array
    {
        $this->links ??= ReportLink::query()->get()->keyBy('id');
        $link = $this->links->get($linkId);
        $source = $link === null ? null : $table->fields->firstWhere('id', $link->source_field_id);
        $target = $link === null ? null : $this->tables->get($link->target_table_id);
        $targetField = $link?->target_field_id === null ? null : $target?->fields->firstWhere('id', $link->target_field_id);

        if ($link === null || $link->source_table_id !== $table->id || ! $source instanceof CustomField || ! $source->is_active
            || $target === null || ($link->target_field_id !== null && ! $targetField?->is_active)
            || ($link->target_field_id === null && $target->isExternal())) {
            $this->error('missing_link', ['label' => $link?->label ?? $spec['label'] ?? '#'.$linkId, 'table' => $table->label]);

            return null;
        }

        if ($this->usableTable($target, (string) $link->label) === null) {
            return null;
        }

        $key = $targetField?->name ?? 'id';

        // Beide Spalten müssen in den Datenständen angebundener Tabellen vorhanden sein.
        foreach ([[$table, $source->name, $source->label], [$target, $key, $targetField?->label ?? 'id']] as [$owner, $column, $label]) {
            if (isset($this->snapshots[$owner->id]) && ! in_array($column, $this->snapshotColumns($owner), true)) {
                $this->error('not_in_snapshot', ['label' => $label, 'table' => $owner->label]);

                return null;
            }
        }

        return [$source, $target, $key, (string) $link->label];
    }

    private function usableTable(?CustomTable $table, string $label): ?CustomTable
    {
        if ($table === null || ! $table->is_active) {
            $this->error('missing_table', ['label' => $table?->label ?? $label]);

            return null;
        }

        if ($table->isExternal()) {
            $snapshot = $this->snapshots[$table->id] ?? $table->latestSnapshot();

            if ($snapshot === null) {
                $this->error('no_snapshot', ['label' => $table->label]);

                return null;
            }

            $this->snapshots[$table->id] = $snapshot;
        }

        return $table;
    }

    /** @return list<string> */
    private function snapshotColumns(CustomTable $table): array
    {
        return $this->snapshotColumns[$table->id] ??= app(DynamicDataNamespace::class)->connection()->getSchemaBuilder()
            ->getColumnListing(app(SnapshotService::class)->qualified($table));
    }

    /** @param  array<string, ResolvedField>  $fields */
    private function checkStructure(ReportDefinition $definition, array $fields): void
    {
        foreach ([...$definition->groups, ...$definition->columns] as $key) {
            if (! isset($fields[$key])) {
                $this->error('unknown_key', ['key' => $key]);
            }
        }

        if (count($definition->columns) > self::MAX_COLUMNS) {
            $this->error('too_many_columns', ['max' => self::MAX_COLUMNS]);
        }

        if ($definition->columns !== [] && $definition->measures === []) {
            $this->error('columns_need_measure');
        }

        if (array_intersect($definition->groups, $definition->columns) !== []) {
            $this->error('group_and_column');
        }

        if (! $definition->isGrouped()) {
            foreach ($definition->show ?? [] as $key) {
                if (! isset($fields[$key]) && $definition->expression($key) === null) {
                    $this->error('unknown_key', ['key' => $key]);
                }
            }

            if ($definition->shownKeys() === []) {
                $this->error('empty');
            }
        }

        $max = (int) config('reports.max_rows', 5000);

        if ($definition->limit !== null && ($definition->limit < 1 || $definition->limit > $max)) {
            $this->error('limit', ['max' => $max]);
        }
    }

    /** @param  array<string, ResolvedField>  $fields */
    private function checkMeasures(ReportDefinition $definition, array $fields): void
    {
        $keys = [];

        foreach ($definition->measures as $measure) {
            if (preg_match(self::KEY_PATTERN, $measure['key']) !== 1 || isset($keys[$measure['key']]) || isset($fields[$measure['key']])) {
                $this->error('duplicate_key', ['key' => $measure['key']]);
            }

            $keys[$measure['key']] = true;

            // Kennzahl aus Werten: eigene Prüfung (checkCalculatedMeasures), braucht alle übrigen Werte.
            if ($measure['agg'] === ReportDefinition::CALC) {
                continue;
            }

            // Berechneter Wert: Formel je Datensatz, zusammengefasst mit Summe, Durchschnitt, Minimum oder Maximum.
            if (isset($measure['expr'])) {
                if (! in_array($measure['agg'], ['sum', 'avg', 'min', 'max'], true) || $measure['field'] !== null) {
                    $this->error('expression_aggregate');
                }

                $this->checkExpression((array) $measure['expr'], $fields);

                continue;
            }

            if (! in_array($measure['agg'], ReportDefinition::AGGREGATES, true)) {
                $this->error('invalid_aggregate', ['agg' => $measure['agg']]);

                continue;
            }

            if ($measure['field'] === null) {
                if ($measure['agg'] !== 'count') {
                    $this->error('aggregate_needs_field', ['agg' => __('reports.aggregates.'.$measure['agg'])]);
                }

                continue;
            }

            $field = $fields[$measure['field']] ?? null;

            if ($field === null) {
                $this->error('unknown_key', ['key' => $measure['field']]);

                continue;
            }

            $fits = match ($measure['agg']) {
                // Personen lassen sich nur zählen.
                'sum', 'avg' => $field->isNumeric(),
                'min', 'max' => $field->isNumeric() || $field->isTemporal(),
                default => true,
            };

            if (! $fits) {
                $this->error('aggregate_type', ['agg' => __('reports.aggregates.'.$measure['agg']), 'label' => $field->label]);
            }
        }
    }

    /**
     * Berechnete Listenspalten: eindeutige Schlüssel, gültige Formel.
     *
     * @param  array<string, ResolvedField>  $fields
     */
    /**
     * Kennzahl aus Werten: Formel über die übrigen Werte derselben Zeile, z. B. Budget ÷ Anzahl. Verweise auf
     * gewöhnliche Werte (keine Kennzahl aus Werten) und auf gruppierte Zahlenfelder ohne Stufe (je Zeile ein Wert).
     *
     * @param  array<string, ResolvedField>  $fields
     */
    private function checkCalculatedMeasures(ReportDefinition $definition, array $fields): void
    {
        $numeric = [];
        $labels = [];

        foreach ([...$definition->groups, ...$definition->columns] as $key) {
            if (isset($fields[$key])) {
                $numeric[$key] = $fields[$key]->bucket === null && $fields[$key]->isNumeric();
                $labels[$key] = $fields[$key]->label;
            }
        }

        foreach ($definition->measures as $measure) {
            if ($measure['agg'] === ReportDefinition::CALC) {
                continue;
            }

            $field = $measure['field'] === null ? null : ($fields[$measure['field']] ?? null);
            $numeric[$measure['key']] = isset($measure['expr']) || in_array($measure['agg'], ['count', 'count_distinct', 'sum', 'avg'], true) || ($field?->isNumeric() ?? false);
            $labels[$measure['key']] = $measure['label'] ?? (isset($measure['expr'])
                ? __('reports.aggregates.'.$measure['agg'])
                : ($field === null ? __('reports.describe.records') : __('reports.measure_label', ['agg' => __('reports.aggregates.'.$measure['agg']), 'field' => $field->label])));
        }

        foreach ($definition->measures as $measure) {
            if ($measure['agg'] !== ReportDefinition::CALC) {
                continue;
            }

            if (! is_array($measure['calc'] ?? null) || $measure['field'] !== null) {
                $this->error('expression_invalid');

                continue;
            }

            foreach (ReportExpression::problems(
                $measure['calc'],
                static fn (string $key): ?bool => $numeric[$key] ?? null,
                static fn (string $key): string => $labels[$key] ?? $key,
            ) as $problem) {
                $this->errors[] = $problem;
            }
        }
    }

    private function checkExpressions(ReportDefinition $definition, array $fields): void
    {
        $taken = [...array_keys($fields), ...array_column($definition->measures, 'key')];

        foreach ($definition->expressions as $expression) {
            if (preg_match(self::KEY_PATTERN, $expression['key']) !== 1 || in_array($expression['key'], $taken, true)) {
                $this->error('duplicate_key', ['key' => $expression['key']]);
            }

            $taken[] = $expression['key'];
            $this->checkExpression($expression['expr'], $fields);
        }
    }

    /**
     * @param  array<string, mixed>  $expr
     * @param  array<string, ResolvedField>  $fields
     */
    private function checkExpression(array $expr, array $fields): void
    {
        foreach (ReportExpression::problems(
            $expr,
            static fn (string $key): ?bool => isset($fields[$key]) ? $fields[$key]->isNumeric() : null,
            static fn (string $key): string => $fields[$key]->label ?? $key,
        ) as $problem) {
            $this->errors[] = $problem;
        }
    }

    /** @param  array<string, ResolvedField>  $fields */
    private function checkFilters(ReportDefinition $definition, array $fields): void
    {
        if (count($definition->filters) > self::MAX_FILTERS) {
            $this->error('too_many_filters', ['max' => self::MAX_FILTERS]);
        }

        foreach ($definition->filters as $filter) {
            $field = $fields[$filter['field']] ?? null;

            if ($field === null) {
                $this->error('unknown_key', ['key' => $filter['field']]);

                continue;
            }

            $kind = self::kind($field);

            if (! in_array($filter['op'], [...self::OPERATORS[$kind], ...self::ANY_OPERATORS], true)) {
                $this->error('invalid_operator', ['label' => $field->label]);

                continue;
            }

            if (! $this->validFilterValue($field, $filter['op'], $filter['value'])) {
                $this->error('invalid_filter_value', ['label' => $field->label]);
            }
        }
    }

    private function validFilterValue(ResolvedField $field, string $op, mixed $value): bool
    {
        $scalar = fn (mixed $v): bool => match (self::kind($field)) {
            'number', 'person' => is_numeric($v),
            'relation' => TableConnectorService::baseType($field->field) === FieldType::Integer ? is_numeric($v) : is_scalar($v) && (string) $v !== '',
            'date' => is_string($v) && strtotime($v) !== false,
            default => is_scalar($v) && (string) $v !== '',
        };

        return match ($op) {
            'is_empty', 'not_empty', 'is_true', 'is_false', 'is_me', 'today', 'this_month', 'last_month', 'this_year', 'last_year' => true,
            'last_days' => is_numeric($value) && (int) $value >= 1 && (int) $value <= 3660,
            'between' => is_array($value) && count($value) === 2 && $scalar(array_values($value)[0]) && $scalar(array_values($value)[1]),
            'in' => is_array($value) && $value !== [] && count($value) <= 100 && array_filter($value, fn (mixed $v): bool => ! $scalar($v)) === [],
            default => $scalar($value),
        };
    }

    /** @param  array<string, ResolvedField>  $fields */
    private function checkSort(ReportDefinition $definition, array $fields): void
    {
        $allowed = $definition->isGrouped()
            ? [...$definition->groups, ...array_column($definition->measures, 'key')]
            : $definition->shownKeys();

        foreach ($definition->sort as $sort) {
            if (! in_array($sort['key'], $allowed, true)) {
                $this->error('invalid_sort', ['key' => $sort['key']]);
            } elseif (! $definition->isGrouped() && ($fields[$sort['key']] ?? null)?->isPerson()) {
                // Die Datenbank kennt nur die Benutzer-ID; eine Liste kann abgeschnitten sein und wird nicht in PHP sortiert.
                $this->error('person_sort', ['label' => $fields[$sort['key']]->label]);
            }
        }
    }

    private function checkVisual(ReportDefinition $definition): void
    {
        $type = (string) ($definition->visual['type'] ?? 'table');

        if (self::visualReason($definition, $type) !== null) {
            $this->error('invalid_visual', ['visual' => __('reports.visuals.'.$type)]);
        }
    }

    /**
     * Warum eine Darstellung nicht zum Aufbau passt (übersetzt), oder null, wenn sie passt.
     */
    public static function visualReason(ReportDefinition $definition, string $type): ?string
    {
        $reason = match (true) {
            ! in_array($type, ReportDefinition::VISUALS, true) => 'unknown',
            $type === 'crosstab' && $definition->columns === [] => 'crosstab',
            in_array($type, ['bar', 'line'], true) && ($definition->groups === [] || $definition->measures === []) => 'chart',
            $type === 'pie' && ($definition->measures === [] || $definition->columns !== [] || count($definition->groups) !== 1) => 'pie',
            $type === 'kpi' && ($definition->groups !== [] || $definition->columns !== [] || count($definition->measures) !== 1) => 'kpi',
            default => null,
        };

        return $reason === null ? null : __('reports.visual_reasons.'.$reason);
    }

    /** Art des Feldes für Filter: text, number, date, boolean, relation, person. */
    public static function kind(ResolvedField $field): string
    {
        return self::kindOfField($field->field);
    }

    /** Art eines Feldes (auch einer Systemspalte) für Filter und Editor. */
    public static function kindOfField(CustomField $field): string
    {
        return SystemFields::isPerson($field) ? 'person' : self::kindOf($field->valueType());
    }

    /** Art eines Wertetyps für Filter: text, number, date, boolean, relation. */
    public static function kindOf(FieldType $type): string
    {
        return match ($type) {
            FieldType::Integer, FieldType::Decimal => 'number',
            FieldType::Date, FieldType::DateTime => 'date',
            FieldType::Boolean => 'boolean',
            FieldType::Relation => 'relation',
            default => 'text',
        };
    }

    /** @param  array<string, mixed>  $params */
    private function error(string $code, array $params = []): void
    {
        $this->errors[] = __('reports.errors.'.$code, $params);
    }
}
