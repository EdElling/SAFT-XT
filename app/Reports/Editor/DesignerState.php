<?php

declare(strict_types=1);

namespace App\Reports\Editor;

use App\Reports\ReportDefinition;
use App\Reports\ReportExpression;

/**
 * Zustand des Editors wie bei einer Pivot-Tabelle und seine Übersetzung in die Definition.
 *
 * - rows:    [ref, bucket, label]   Zeilen – ohne Werte die Spalten einer Liste
 * - columns: [ref, bucket]          Spalten der Kreuztabelle (höchstens eine)
 * - values:  [agg, ref|null, label] Werte; ohne Werte und Spalten ist es eine Liste
 * - filters: [ref, op, value, value_to, values]
 * - sort:    Token „row:N“ bzw. „value:N“, sortDir, limit, visual
 *
 * Feld-Referenzen siehe FieldCatalog ("57/63:31").
 */
final class DesignerState
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $values
     * @param  list<array<string, mixed>>  $filters
     */
    public function __construct(
        public ?int $base = null,
        public array $rows = [],
        public array $columns = [],
        public array $values = [],
        public array $filters = [],
        public ?string $sort = null,
        public string $sortDir = 'asc',
        public ?int $limit = null,
        public string $visual = 'table',
    ) {}

    public function isGrouped(): bool
    {
        return $this->values !== [] || $this->columns !== [];
    }

    /**
     * Definition und die Zuordnung Ergebnisspalte => Sortier-Token (für Klicks auf Spaltenköpfe).
     *
     * @return array{0: ReportDefinition, 1: array<string, string>}
     */
    public function toDefinition(): array
    {
        $fields = [];
        $key = function (?string $ref, ?string $bucket = null, ?string $label = null) use (&$fields): ?string {
            if (blank($ref)) {
                return null;
            }

            $id = $ref.'|'.($bucket ?? '').'|'.($label ?? '');

            if (! isset($fields[$id])) {
                $parsed = FieldCatalog::parse((string) $ref);
                $fields[$id] = ['key' => 'f'.(count($fields) + 1), 'path' => $parsed['path'], 'field' => $parsed['field'], 'bucket' => $bucket, 'label' => $label];
            }

            return $fields[$id]['key'];
        };
        $text = static fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        $grouped = $this->isGrouped();
        $tokens = [];
        $rowKeys = [];
        $rows = [];
        $expressions = [];
        // Formeln verweisen im Editor auf Feldreferenzen, in der Definition auf Feldschlüssel.
        $formula = fn (array $expr): array => ReportExpression::mapFields($expr, fn (string $ref): string => (string) $key($ref));

        foreach ($this->rows as $i => $row) {
            if (is_array($row['expr'] ?? null)) {
                if (! $grouped) {
                    $k = 'e'.(count($expressions) + 1);
                    $expressions[] = ['key' => $k, 'expr' => $formula($row['expr']), 'label' => $text($row['label'] ?? null), 'scale' => $row['scale'] ?? 2];
                    $rows[] = $k;
                    $tokens[$k] = "row:{$i}";
                }

                continue;
            }

            if (($k = $key($row['ref'] ?? null, $grouped ? $text($row['bucket'] ?? null) : null, $text($row['label'] ?? null))) !== null) {
                $rows[] = $k;
                $tokens[$k] = "row:{$i}";
                $rowKeys[(string) $row['ref']] ??= $k;
            }
        }

        $columns = [];

        foreach (array_slice($this->columns, 0, 1) as $column) {
            if (($k = $key($column['ref'] ?? null, $text($column['bucket'] ?? null))) !== null) {
                $columns[] = $k;
            }
        }

        $measures = [];
        $measureKeys = self::measureKeys($this->values);
        // Kennzahl aus Werten: „@<Feldreferenz>“ = gruppierte Spalte, sonst die Kennung eines Werts (= sein Schlüssel).
        $calc = fn (array $tree): array => ReportExpression::mapFields($tree, fn (string $id): string => str_starts_with($id, '@')
            ? ($rowKeys[substr($id, 1)] ?? (string) $key(substr($id, 1)))
            : $id);

        foreach ($this->values as $i => $value) {
            $mk = $measureKeys[$i];
            $tokens[$mk] = "value:{$i}";

            // Kennzahl aus Werten: Formel über die Kennungen der übrigen Werte (= ihre Schlüssel in der Definition).
            if (($value['agg'] ?? null) === ReportDefinition::CALC) {
                $measures[] = ['key' => $mk, 'field' => null, 'agg' => ReportDefinition::CALC, 'label' => $text($value['label'] ?? null), 'calc' => $calc((array) ($value['calc'] ?? [])), 'scale' => $value['scale'] ?? 2];

                continue;
            }

            if (is_array($value['expr'] ?? null)) {
                $measures[] = ['key' => $mk, 'field' => null, 'agg' => (string) ($value['agg'] ?? 'sum'), 'label' => $text($value['label'] ?? null), 'expr' => $formula($value['expr']), 'scale' => $value['scale'] ?? 2];

                continue;
            }

            $measures[] = [
                'key' => $mk,
                'field' => $key($value['ref'] ?? null),
                'agg' => (string) ($value['agg'] ?? 'count'),
                'label' => $text($value['label'] ?? null),
            ];
        }

        $filters = [];

        foreach ($this->filters as $filter) {
            if (($k = $key($filter['ref'] ?? null)) === null || blank($filter['op'] ?? null)) {
                continue;
            }

            $filters[] = ['field' => $k, 'op' => (string) $filter['op'], 'value' => match ($filter['op']) {
                'between' => [$filter['value'] ?? null, $filter['value_to'] ?? null],
                'in' => array_values(array_filter((array) ($filter['values'] ?? []), 'filled')),
                default => $filter['value'] ?? null,
            }];
        }

        $sortKey = array_search($this->sort, $tokens, true);

        $definition = ReportDefinition::fromArray([
            'base' => (int) $this->base,
            'fields' => array_values($fields),
            'groups' => $grouped ? $rows : [],
            'columns' => $columns,
            'measures' => $measures,
            'filters' => $filters,
            'sort' => $sortKey === false ? [] : [['key' => $sortKey, 'dir' => $this->sortDir]],
            'limit' => $this->limit,
            'visual' => ['type' => $this->visual],
            'show' => $grouped ? null : $rows,
            'expressions' => $expressions,
        ]);

        return [$definition, $tokens];
    }

    /**
     * Schlüssel der Werte in der Definition: die feste Kennung (id) eines Werts, sonst m1, m2 … (ohne Doppelte).
     *
     * @param  list<array<string, mixed>>  $values
     * @return list<string>
     */
    public static function measureKeys(array $values): array
    {
        $taken = [];

        foreach ($values as $value) {
            if (is_string($value['id'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,30}$/', $value['id']) === 1) {
                $taken[$value['id']] = true;
            }
        }

        $keys = [];
        $n = 0;

        foreach ($values as $value) {
            $id = $value['id'] ?? null;

            if (is_string($id) && isset($taken[$id]) && ! in_array($id, $keys, true)) {
                $keys[] = $id;

                continue;
            }

            do {
                $candidate = 'm'.++$n;
            } while (isset($taken[$candidate]));

            $keys[] = $candidate;
        }

        return $keys;
    }

    /** Neue feste Kennung für einen Wert (für Verweise einer Kennzahl aus Werten). */
    public static function newValueId(): string
    {
        return 'v'.bin2hex(random_bytes(3));
    }

    public static function fromDefinition(ReportDefinition $definition): self
    {
        $ref = function (?string $key) use ($definition): ?string {
            $field = $key === null ? null : $definition->field($key);

            return $field === null ? null : FieldCatalog::ref($field['path'], $field['field']);
        };
        $formula = fn (array $expr): array => ReportExpression::mapFields($expr, fn (string $key): string => (string) $ref($key));
        $grouped = $definition->isGrouped();
        $rowKeys = $grouped ? $definition->groups : $definition->shownKeys();
        $tokens = [];

        foreach ($rowKeys as $i => $key) {
            $tokens[$key] = "row:{$i}";
        }

        foreach ($definition->measures as $i => $measure) {
            $tokens[$measure['key']] = "value:{$i}";
        }

        $sort = $definition->sort[0] ?? null;

        return new self(
            base: $definition->base ?: null,
            rows: array_map(fn (string $key): array => ($expression = $definition->expression($key)) !== null
                ? ['expr' => $formula($expression['expr']), 'label' => $expression['label'], 'scale' => $expression['scale']]
                : [
                    'ref' => $ref($key),
                    'bucket' => $definition->field($key)['bucket'] ?? null,
                    'label' => $definition->field($key)['label'] ?? null,
                ], $rowKeys),
            columns: array_map(fn (string $key): array => ['ref' => $ref($key), 'bucket' => $definition->field($key)['bucket'] ?? null], $definition->columns),
            values: array_map(fn (array $m): array => ['id' => $m['key'], ...match (true) {
                $m['agg'] === ReportDefinition::CALC => ['agg' => ReportDefinition::CALC, 'ref' => null, 'label' => $m['label'], 'scale' => $m['scale'] ?? 2,
                    'calc' => ReportExpression::mapFields((array) ($m['calc'] ?? []), fn (string $key): string => in_array($key, [...$definition->groups, ...$definition->columns], true) ? '@'.$ref($key) : $key)],
                isset($m['expr']) => ['agg' => $m['agg'], 'ref' => null, 'label' => $m['label'], 'expr' => $formula((array) $m['expr']), 'scale' => $m['scale'] ?? 2],
                default => ['agg' => $m['agg'], 'ref' => $ref($m['field']), 'label' => $m['label']],
            }], $definition->measures),
            filters: array_map(fn (array $f): array => [
                'ref' => $ref($f['field']),
                'op' => $f['op'],
                'value' => match ($f['op']) {
                    'between' => array_values((array) $f['value'])[0] ?? null,
                    'in' => null,
                    default => $f['value'],
                },
                'value_to' => $f['op'] === 'between' ? (array_values((array) $f['value'])[1] ?? null) : null,
                'values' => $f['op'] === 'in' ? array_values((array) $f['value']) : [],
            ], $definition->filters),
            sort: $sort === null ? null : ($tokens[$sort['key']] ?? null),
            sortDir: $sort['dir'] ?? 'asc',
            limit: $definition->limit,
            visual: (string) ($definition->visual['type'] ?? 'table'),
        );
    }

    /** @return array<string, mixed> für Livewire */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            base: filled($data['base'] ?? null) ? (int) $data['base'] : null,
            rows: array_values((array) ($data['rows'] ?? [])),
            columns: array_values((array) ($data['columns'] ?? [])),
            values: array_values((array) ($data['values'] ?? [])),
            filters: array_values((array) ($data['filters'] ?? [])),
            sort: $data['sort'] ?? null,
            sortDir: ($data['sortDir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            limit: filled($data['limit'] ?? null) ? (int) $data['limit'] : null,
            visual: (string) ($data['visual'] ?? 'table'),
        );
    }
}
