<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Apps\AppInterfaces;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Schema\FieldDefinitionNormalizer;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Datensatz kopieren (B-120) samt Kind-Datensätzen (B-123): Mitkopiert werden Tabellen, deren Verknüpfung auf die
 * kopierte Tabelle die Option „beim Kopieren des übergeordneten Datensatzes mitkopieren“ trägt – über mehrere Ebenen,
 * nur innerhalb der App und nur mit Anlegerecht. Selbstreferenzen (Hierarchien) sind ausgenommen und behalten den Wert
 * des Originals. Verweise auf mitkopierte Datensätze zeigen auf deren Kopie, alle anderen bleiben; Dateien werden
 * kopiert, eindeutige Felder geleert, berechnete Felder neu gerechnet. Alles in einer Transaktion.
 */
final class RecordCopier
{
    /** Höchstens so viele Datensätze je Kopie (Original mitgezählt); config dynamic_data.copy_max_records. */
    public static function limit(): int
    {
        return max(1, (int) config('dynamic_data.copy_max_records', 1000));
    }

    public function __construct(private readonly RecordFiles $files) {}

    /**
     * Mitkopierbare Kind-Tabellen als Baum (je Knoten die Kind-Datensätze der übergeordneten Datensätze).
     *
     * @param  list<int|string>  $ids  übergeordnete Datensätze
     * @param  list<string>  $trail  Tabellen auf dem Weg (gegen Kreise)
     * @return list<array{key: string, table: CustomTable, via: string, ids: list<int|string>, children: list<array<string, mixed>>}>
     */
    public function tree(CustomTable $table, array $ids, array $trail = []): array
    {
        $nodes = [];
        $trail = [...$trail, $table->name];
        $relations = CustomField::query()->with('custom_table.fields')
            ->where('type', FieldType::Relation->value)
            ->where('is_active', true)
            ->where('options->table', $table->name)
            ->orderBy('id')
            ->get();

        foreach ($relations as $relation) {
            $child = $relation->custom_table;

            if (! $child instanceof CustomTable || ! ($relation->optionsArray()[FieldDefinitionNormalizer::COPY_WITH_PARENT] ?? false)
                || in_array($child->name, $trail, true) || $child->isExternal() || AppInterfaces::foreign($child, $table->app_id)
                || ! $child->supports('create') || ! DataAccess::current($child, 'create')) {
                continue;
            }

            $childIds = $ids === [] ? [] : DynamicRecord::forTable($child)->newQuery()->whereIn($relation->name, $ids)->orderBy('id')->pluck('id')->all();
            $nodes[] = [
                'key' => $child->name.'.'.$relation->name,
                'table' => $child,
                'via' => $relation->name,
                'ids' => $childIds,
                'children' => $this->tree($child, $childIds, $trail),
            ];
        }

        return $nodes;
    }

    /**
     * Auswahl im Kopier-Dialog: je Kind-Tabelle der ersten Ebene „Bezeichnung (Anzahl)“, darunter liegende Ebenen genannt.
     *
     * @return array<string, string>
     */
    public function options(CustomTable $table, DynamicRecord $record): array
    {
        $options = [];

        foreach ($this->tree($table, [$record->getKey()]) as $node) {
            $nested = $this->describe($node['children']);
            $options[$node['key']] = (string) $node['table']->label.' ('.count($node['ids']).')'
                .($nested === '' ? '' : ' – '.__('data.copy.nested', ['tables' => $nested]));
        }

        return $options;
    }

    /**
     * Kopie anlegen. $data sind die Werte aus dem Formular, $locked die Verknüpfung zur Haupttabelle (bleibt die des
     * Originals), $chosen die gewählten Kind-Tabellen der ersten Ebene („tabelle.verknüpfung“).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $locked
     * @param  list<string>  $chosen
     * @return array{record: DynamicRecord, children: int}
     */
    public function copy(CustomTable $table, DynamicRecord $record, array $data, array $locked = [], array $chosen = []): array
    {
        $tree = array_values(array_filter($this->tree($table, [$record->getKey()]), static fn (array $node): bool => in_array($node['key'], $chosen, true)));
        $count = $this->count($tree);

        if ($count + 1 > self::limit()) {
            throw new BusinessRuleException(__('data.copy.too_many', ['count' => $count + 1, 'limit' => self::limit()]));
        }

        $this->assertCopyable($tree);

        return app(DynamicDataNamespace::class)->connection()->transaction(function () use ($table, $record, $data, $locked, $tree, $count): array {
            $copy = DynamicRecord::forTable($table)->fill($this->files->copyOwned($table, $data))
                ->forceFill(array_intersect_key($record->getAttributes(), array_flip($locked)));
            $copy->save();

            $map = [$table->name => [(string) $record->getKey() => $copy->getKey()]];
            $this->copyNodes($tree, $map);

            return ['record' => $copy, 'children' => $count];
        });
    }

    /**
     * Werte eines Datensatzes für die Kopie: alle aktiven Felder ausser eindeutigen, berechneten und dem Schlüssel
     * einer angebundenen Tabelle.
     *
     * @return array<string, mixed>
     */
    public static function values(CustomTable $table, DynamicRecord $record): array
    {
        $skip = $table->isExternal() ? $table->keyColumns() : [];
        $active = $table->fields->where('is_active', true);
        $values = array_intersect_key($record->attributesToArray(), array_flip($active->pluck('name')->all()));

        foreach ($active as $field) {
            if ($field->is_unique || $field->type === FieldType::Computed || in_array($field->name, $skip, true)) {
                unset($values[$field->name]);
            }
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, array<string, int|string>>  $map  Tabelle => alte ID => neue ID
     */
    private function copyNodes(array $nodes, array &$map): void
    {
        foreach ($nodes as $node) {
            /** @var CustomTable $child */
            $child = $node['table'];
            $parent = (string) $child->fields->firstWhere('name', $node['via'])?->optionsArray()['table'];

            foreach (DynamicRecord::forTable($child)->newQuery()->whereIn('id', $node['ids'])->orderBy('id')->get() as $row) {
                $values = $this->files->copyOwned($child, self::values($child, $row));

                // Verweise auf schon kopierte Datensätze (auch der übergeordnete) zeigen auf die Kopie; Selbstreferenzen nicht.
                foreach ($child->fields->where('type', FieldType::Relation) as $relation) {
                    $target = (string) ($relation->optionsArray()['table'] ?? '');
                    $old = $row->getAttribute($relation->name);

                    if ($target !== $child->name && $old !== null && isset($map[$target][(string) $old])) {
                        $values[$relation->name] = $map[$target][(string) $old];
                    }
                }

                $copy = DynamicRecord::forTable($child)->fill($values)->forceFill([$node['via'] => $map[$parent][(string) $row->getAttribute($node['via'])]]);
                $copy->save();
                $map[$child->name][(string) $row->getKey()] = $copy->getKey();
            }

            $this->copyNodes($node['children'], $map);
        }
    }

    /**
     * Eindeutige Pflichtfelder lassen sich in Kopien nicht leeren: dann gar nicht kopieren.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function assertCopyable(array $nodes): void
    {
        foreach ($nodes as $node) {
            $blocking = $node['table']->fields->first(static fn (CustomField $f): bool => $f->is_active && $f->is_unique && $f->is_required);

            if ($blocking instanceof CustomField && $node['ids'] !== []) {
                throw new BusinessRuleException(__('data.copy.unique_required', ['field' => (string) $blocking->label, 'table' => (string) $node['table']->label]));
            }

            $this->assertCopyable($node['children']);
        }
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function count(array $nodes): int
    {
        return array_sum(array_map(fn (array $node): int => count($node['ids']) + $this->count($node['children']), $nodes));
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function describe(array $nodes): string
    {
        return implode(', ', array_map(function (array $node): string {
            $nested = $this->describe($node['children']);

            return (string) $node['table']->label.' ('.count($node['ids']).')'.($nested === '' ? '' : ', '.$nested);
        }, $nodes));
    }
}
