<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * Definition einer Auswertung (Version 1) – gespeichert als JSON, nie als SQL.
 *
 * - base: ID der Grundtabelle
 * - fields: verwendete Felder mit Schlüssel, Verknüpfungspfad (IDs der Verknüpfungsfelder
 *   ab der Grundtabelle) und Feld-ID; Datumsfelder optional mit Stufe (bucket)
 * - groups / columns: Schlüssel der Gruppenfelder bzw. der Spalten einer Kreuztabelle
 * - measures: Werte (Aggregat über ein Feld oder Anzahl)
 * - filters, sort, limit, visual
 *
 * Ohne Gruppen und Werte ist es eine Liste (ein Datensatz je Zeile); show nennt deren Spalten
 * (fehlt es, alle Felder – Felder nur für Filter lassen sich so ausblenden).
 * Dieses Wertobjekt normalisiert nur die Form; die Prüfung gegen das Datenmodell macht
 * DefinitionResolver.
 */
final readonly class ReportDefinition
{
    public const int VERSION = 1;

    public const array BUCKETS = ['day', 'week', 'month', 'quarter', 'year'];

    public const array AGGREGATES = ['count', 'count_distinct', 'sum', 'avg', 'min', 'max'];

    /** Kennzahl aus Werten: Formel über die anderen Werte derselben Zeile (nach dem Zusammenfassen). */
    public const string CALC = 'calc';

    public const array VISUALS = ['table', 'crosstab', 'bar', 'line', 'pie', 'kpi'];

    /**
     * @param  list<array{key: string, path: list<int>, field: int, bucket: string|null, label: string|null}>  $fields
     * @param  list<string>  $groups
     * @param  list<string>  $columns
     * @param  list<array{key: string, field: string|null, agg: string, label: string|null, expr?: array<string, mixed>|null, calc?: array<string, mixed>|null, scale?: int|null}>  $measures  expr = Formel je Datensatz (statt field); agg calc mit calc = Formel über andere Werte
     * @param  list<array{field: string, op: string, value: mixed}>  $filters
     * @param  list<array{key: string, dir: string}>  $sort
     * @param  array<string, mixed>  $visual
     * @param  list<string>|null  $show  Spalten einer Liste
     * @param  list<array{key: string, expr: array<string, mixed>, label: string|null, scale: int}>  $expressions  berechnete Listenspalten
     */
    public function __construct(
        public int $base,
        public array $fields = [],
        public array $groups = [],
        public array $columns = [],
        public array $measures = [],
        public array $filters = [],
        public array $sort = [],
        public ?int $limit = null,
        public array $visual = ['type' => 'table'],
        public ?array $show = null,
        public array $expressions = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $strings = static fn (mixed $list): array => array_values(array_map('strval', array_filter((array) $list, 'is_scalar')));
        $text = static fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;

        return new self(
            base: (int) ($data['base'] ?? 0),
            fields: array_values(array_map(static fn (array $f): array => [
                'key' => (string) ($f['key'] ?? ''),
                'path' => array_values(array_map('intval', (array) ($f['path'] ?? []))),
                'field' => (int) ($f['field'] ?? 0),
                'bucket' => $text($f['bucket'] ?? null),
                'label' => $text($f['label'] ?? null),
            ], array_filter((array) ($data['fields'] ?? []), 'is_array'))),
            groups: $strings($data['groups'] ?? []),
            columns: $strings($data['columns'] ?? []),
            measures: array_values(array_map(static fn (array $m): array => [
                'key' => (string) ($m['key'] ?? ''),
                'field' => $text($m['field'] ?? null),
                'agg' => (string) ($m['agg'] ?? 'count'),
                'label' => $text($m['label'] ?? null),
                ...(is_array($m['expr'] ?? null) ? ['expr' => $m['expr'], 'scale' => self::scale($m['scale'] ?? null)] : []),
                ...(is_array($m['calc'] ?? null) ? ['calc' => $m['calc'], 'scale' => self::scale($m['scale'] ?? null)] : []),
            ], array_filter((array) ($data['measures'] ?? []), 'is_array'))),
            filters: array_values(array_map(static fn (array $f): array => [
                'field' => (string) ($f['field'] ?? ''),
                'op' => (string) ($f['op'] ?? ''),
                'value' => $f['value'] ?? null,
            ], array_filter((array) ($data['filters'] ?? []), 'is_array'))),
            sort: array_values(array_map(static fn (array $s): array => [
                'key' => (string) ($s['key'] ?? ''),
                'dir' => ($s['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            ], array_filter((array) ($data['sort'] ?? []), 'is_array'))),
            limit: isset($data['limit']) && is_numeric($data['limit']) ? (int) $data['limit'] : null,
            visual: is_array($data['visual'] ?? null) && isset($data['visual']['type']) ? $data['visual'] : ['type' => 'table'],
            show: isset($data['show']) && is_array($data['show']) ? $strings($data['show']) : null,
            expressions: array_values(array_map(static fn (array $e): array => [
                'key' => (string) ($e['key'] ?? ''),
                'expr' => (array) $e['expr'],
                'label' => $text($e['label'] ?? null),
                'scale' => self::scale($e['scale'] ?? null),
            ], array_filter((array) ($data['expressions'] ?? []), static fn (mixed $e): bool => is_array($e) && is_array($e['expr'] ?? null)))),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'base' => $this->base,
            'fields' => $this->fields,
            'groups' => $this->groups,
            'columns' => $this->columns,
            'measures' => $this->measures,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'limit' => $this->limit,
            'visual' => $this->visual,
            'show' => $this->show,
            // Nur bei Bedarf, damit Auswertungen ohne Formeln unverändert gespeichert bleiben.
            ...($this->expressions === [] ? [] : ['expressions' => $this->expressions]),
        ];
    }

    /** Nachkommastellen einer Formel (Vorgabe 2). */
    private static function scale(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(ReportExpression::MAX_SCALE, (int) $value)) : 2;
    }

    /** @return array{key: string, expr: array<string, mixed>, label: string|null, scale: int}|null */
    public function expression(string $key): ?array
    {
        foreach ($this->expressions as $expression) {
            if ($expression['key'] === $key) {
                return $expression;
            }
        }

        return null;
    }

    /**
     * Spalten einer Liste: show, sonst alle Felder.
     *
     * @return list<string>
     */
    public function shownKeys(): array
    {
        return $this->show ?? array_column($this->fields, 'key');
    }

    /** Gruppiert (Gruppen oder Werte) statt Liste. */
    public function isGrouped(): bool
    {
        return $this->groups !== [] || $this->measures !== [];
    }

    /** @return array{key: string, path: list<int>, field: int, bucket: string|null, label: string|null}|null */
    public function field(string $key): ?array
    {
        foreach ($this->fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }
}
