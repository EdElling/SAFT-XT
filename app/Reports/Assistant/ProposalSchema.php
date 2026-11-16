<?php

declare(strict_types=1);

namespace App\Reports\Assistant;

use App\Reports\DefinitionResolver;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportExpression;

/**
 * JSON-Schema der Antwort des Modells (strukturierte Ausgabe) und Übersetzung in den Zustand des Editors
 * (App\Reports\Editor\DesignerState). Feldreferenzen wie im Editor: "<pfad>:<feld-id>".
 *
 * Berechnete Werte und Listenspalten kommen als Formeltext mit Referenzen in eckigen Klammern, z. B. "[:31] * [12:40]";
 * SAFT setzt ihn in den Formelbaum um und prüft ihn wie eine Eingabe im Editor.
 */
final class ProposalSchema
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $nullable = static fn (array $type): array => ['anyOf' => [$type, ['type' => 'null']]];
        $object = static fn (array $properties): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
        $field = $object(['ref' => ['type' => 'string'], 'bucket' => $nullable(['type' => 'string', 'enum' => ReportDefinition::BUCKETS])]);
        $calculated = [
            'formula' => $nullable(['type' => 'string']),
            'label' => $nullable(['type' => 'string']),
            'scale' => $nullable(['type' => 'integer']),
        ];

        return $object([
            'kind' => ['type' => 'string', 'enum' => ['report', 'question']],
            'question' => $nullable(['type' => 'string']),
            'title' => $nullable(['type' => 'string']),
            'base' => $nullable(['type' => 'integer']),
            'rows' => ['type' => 'array', 'items' => $object([
                'ref' => ['type' => 'string'],
                'bucket' => $nullable(['type' => 'string', 'enum' => ReportDefinition::BUCKETS]),
                ...$calculated,
            ])],
            'columns' => ['type' => 'array', 'items' => $field],
            'values' => ['type' => 'array', 'items' => $object([
                'agg' => ['type' => 'string', 'enum' => [...ReportDefinition::AGGREGATES, ReportDefinition::CALC]],
                'ref' => $nullable(['type' => 'string']),
                ...$calculated,
            ])],
            'filters' => ['type' => 'array', 'items' => $object([
                'ref' => ['type' => 'string'],
                'op' => ['type' => 'string', 'enum' => self::operators()],
                'value' => $nullable(['type' => 'string']),
                'value_to' => $nullable(['type' => 'string']),
                'values' => ['type' => 'array', 'items' => ['type' => 'string']],
            ])],
            'sort' => $nullable($object([
                'target' => ['type' => 'string', 'enum' => ['row', 'value']],
                'index' => ['type' => 'integer'],
                'dir' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ])),
            'limit' => $nullable(['type' => 'integer']),
            'visual' => ['type' => 'string', 'enum' => ReportDefinition::VISUALS],
        ]);
    }

    /** @return list<string> alle Filter-Operatoren */
    public static function operators(): array
    {
        return array_values(array_unique([...array_merge(...array_values(DefinitionResolver::OPERATORS)), ...DefinitionResolver::ANY_OPERATORS]));
    }

    /**
     * Vorschlag → Zustand des Editors (Array wie DesignerState::toArray()).
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public static function toState(array $proposal): array
    {
        $list = static fn (mixed $v): array => array_values(array_filter((array) $v, 'is_array'));
        $text = static fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $sort = is_array($proposal['sort'] ?? null) ? $proposal['sort'] : null;

        return [
            'base' => is_numeric($proposal['base'] ?? null) ? (int) $proposal['base'] : null,
            'rows' => array_map(fn (array $r): array => $text($r['formula'] ?? null) !== null
                ? ['ref' => null, 'bucket' => null, ...self::calculated($r)]
                : ['ref' => (string) ($r['ref'] ?? ''), 'bucket' => $text($r['bucket'] ?? null), 'label' => null], $list($proposal['rows'] ?? [])),
            'columns' => array_map(fn (array $c): array => ['ref' => (string) ($c['ref'] ?? ''), 'bucket' => $text($c['bucket'] ?? null)], array_slice($list($proposal['columns'] ?? []), 0, 1)),
            // Werte mit fester Kennung m1, m2 …: Eine Kennzahl aus Werten verweist mit [#n] auf den n-ten Wert.
            'values' => array_map(fn (array $v, int $i): array => ['id' => 'm'.($i + 1), ...match (true) {
                ($v['agg'] ?? null) === ReportDefinition::CALC => ['agg' => ReportDefinition::CALC, 'ref' => null, ...self::calculated($v, true)],
                $text($v['formula'] ?? null) !== null => ['agg' => (string) ($v['agg'] ?? 'sum'), 'ref' => null, ...self::calculated($v)],
                default => ['agg' => (string) ($v['agg'] ?? 'count'), 'ref' => $text($v['ref'] ?? null), 'label' => null],
            }], $values = $list($proposal['values'] ?? []), array_keys($values)),
            'filters' => array_map(fn (array $f): array => [
                'ref' => (string) ($f['ref'] ?? ''),
                'op' => (string) ($f['op'] ?? ''),
                'value' => $text($f['value'] ?? null),
                'value_to' => $text($f['value_to'] ?? null),
                'values' => array_values(array_map('strval', array_filter((array) ($f['values'] ?? []), 'is_scalar'))),
            ], $list($proposal['filters'] ?? [])),
            'sort' => $sort === null ? null : ($sort['target'] === 'value' ? 'value' : 'row').':'.max(0, (int) ($sort['index'] ?? 0)),
            'sortDir' => ($sort['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'limit' => is_numeric($proposal['limit'] ?? null) && (int) $proposal['limit'] > 0 ? (int) $proposal['limit'] : null,
            'visual' => in_array($proposal['visual'] ?? null, ReportDefinition::VISUALS, true) ? $proposal['visual'] : 'table',
        ];
    }

    /**
     * Formeltext → Formelbaum mit Feldreferenzen. Ein Text, der sich nicht lesen lässt, ergibt formula_error
     * (wird dem Modell zur Korrektur zurückgegeben).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function calculated(array $item, bool $overValues = false): array
    {
        $formula = trim((string) ($item['formula'] ?? ''));
        $label = is_scalar($item['label'] ?? null) && trim((string) $item['label']) !== '' ? mb_substr(trim((string) $item['label']), 0, 100) : null;
        $scale = is_numeric($item['scale'] ?? null) ? max(0, min(ReportExpression::MAX_SCALE, (int) $item['scale'])) : 2;
        preg_match_all('/\[([^\]]*)\]/u', $formula, $matches);
        $refs = array_map('trim', $matches[1]);

        // Kennzahl aus Werten: [#n] = n-ter Wert (Kennung mn), sonst eine gruppierte Spalte (@Feldreferenz).
        $keys = $overValues
            ? array_combine($refs, array_map(static fn (string $r): string => preg_match('/^#(\d+)$/', $r, $m) === 1 ? 'm'.$m[1] : '@'.$r, $refs))
            : array_combine($refs, $refs);

        try {
            return [$overValues ? 'calc' : 'expr' => ReportExpression::parse($formula, $keys), 'label' => $label, 'scale' => $scale];
        } catch (InvalidReportException $e) {
            return [$overValues ? 'calc' : 'expr' => null, 'label' => $label, 'scale' => $scale, 'formula_error' => $formula.': '.implode(' ', $e->errors)];
        }
    }

    /** Formel im Editor-Zustand als Text für das Modell: "[:31] × [12:40]". */
    public static function formulaText(array $expr): string
    {
        return ReportExpression::text($expr, static fn (string $ref): string => $ref);
    }

    /**
     * Kennzahl aus Werten als Text für das Modell: Werte als [#n] (Position), gruppierte Spalten als [Feldreferenz].
     *
     * @param  array<string, mixed>  $calc
     * @param  list<array<string, mixed>>  $values
     */
    public static function calcText(array $calc, array $values): string
    {
        $positions = [];

        foreach (array_values($values) as $i => $value) {
            if (is_string($value['id'] ?? null)) {
                $positions[$value['id']] = '#'.($i + 1);
            }
        }

        return ReportExpression::text($calc, static fn (string $id): string => str_starts_with($id, '@') ? substr($id, 1) : ($positions[$id] ?? $id));
    }
}
