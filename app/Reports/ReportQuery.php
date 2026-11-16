<?php

declare(strict_types=1);

namespace App\Reports;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Schema\FieldType;
use App\ExternalData\Snapshots\SnapshotService;
use App\Models\CustomTable;
use App\Reports\Dialects\QueryDialect;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Baut aus einer aufgelösten Auswertung die Abfrage – ausschließlich mit dem Query Builder
 * und gebundenen Werten; nur Datums-Stufen und „enthält“ kommen aus dem Dialekt.
 *
 * Verknüpfte Tabellen werden per LEFT JOIN zur übergeordneten Tabelle angehängt, gelöschte
 * Datensätze zählen nie mit (auch nicht als übergeordneter Datensatz). Angebundene Tabellen
 * kommen aus ihrem Datenstand (Kopie mit _snapshot), verknüpft über ihren Schlüssel.
 */
final class ReportQuery
{
    public function __construct(private readonly DynamicDataNamespace $namespace) {}

    public function build(ResolvedReport $report, QueryDialect $dialect): Builder
    {
        $connection = $this->namespace->connection();
        $query = $connection->table($this->source($report, $report->base).' as t0');
        $this->current($query, $report, $report->base, 't0');

        foreach ($report->joins as $join) {
            $query->leftJoin($this->source($report, $join['table']).' as '.$join['alias'], function (JoinClause $on) use ($join, $report): void {
                $on->on($join['alias'].'.'.$join['key'], '=', $join['parent'].'.'.$join['relation']->name);
                $this->current($on, $report, $join['table'], $join['alias']);
            });
        }

        $definition = $report->definition;

        if ($definition->isGrouped()) {
            $this->grouped($query, $report, $dialect);
        } else {
            foreach ($definition->shownKeys() as $key) {
                // Berechnete Listenspalte: Formel je Datensatz.
                if (($expression = $definition->expression($key)) !== null) {
                    $query->addSelect(DB::raw($this->formula($query, $report, $expression['expr'], $dialect).' as '.$this->wrap($query, $key)));

                    continue;
                }

                $query->addSelect($this->select($query, $this->expression($query, $report->field($key), $dialect), $key));
                $this->selectDisplay($query, $report->field($key), false);
            }
        }

        foreach ($definition->filters as $filter) {
            $this->filter($query, $report->field($filter['field']), $filter['op'], $filter['value'], $dialect);
        }

        foreach ($definition->sort as $sort) {
            $this->orderBy($query, $report, $sort['key'], $sort['dir']);
        }

        if ($definition->sort === []) {
            $definition->isGrouped()
                ? array_map(fn (string $key) => $this->orderBy($query, $report, $key, 'asc'), [...$definition->groups, ...$definition->columns])
                : $query->orderBy($report->snapshot($report->base) === null ? 't0.id' : 't0.'.SnapshotService::ROW_COLUMN);
        }

        return $query;
    }

    private function source(ResolvedReport $report, CustomTable $table): string
    {
        return $report->snapshot($table) === null
            ? $this->namespace->qualify($table->name)
            : $this->namespace->qualify(SnapshotService::tableName($table));
    }

    /** Nur gültige Zeilen: nicht gelöscht bzw. aus dem verwendeten Datenstand. */
    private function current(Builder $query, ResolvedReport $report, CustomTable $table, string $alias): void
    {
        $snapshot = $report->snapshot($table);
        $snapshot === null
            ? $query->whereNull($alias.'.deleted_at')
            : $query->where($alias.'.'.SnapshotService::SNAPSHOT_COLUMN, $snapshot->id);
    }

    private function grouped(Builder $query, ResolvedReport $report, QueryDialect $dialect): void
    {
        $definition = $report->definition;

        foreach ([...$definition->groups, ...$definition->columns] as $key) {
            $expression = $this->expression($query, $report->field($key), $dialect);
            $query->addSelect($this->select($query, $expression, $key))->groupBy($expression);
            $this->selectDisplay($query, $report->field($key), true);
        }

        $sql = [];

        foreach ($definition->measures as $measure) {
            if ($measure['agg'] !== ReportDefinition::CALC) {
                $sql[$measure['key']] = $this->measureSql($query, $report, $measure, $dialect);
            }
        }

        foreach ($definition->measures as $measure) {
            // Kennzahl aus Werten: dieselben Zusammenfassungen noch einmal eingesetzt (Aliasse sind in SELECT nicht nutzbar),
            // gruppierte Felder als Spalte (sie stehen im GROUP BY).
            $expression = $measure['agg'] === ReportDefinition::CALC
                ? ReportExpression::sql((array) $measure['calc'], fn (string $key): string => isset($sql[$key]) ? '('.$sql[$key].')' : $this->wrap($query, $report->field($key)->column()), $dialect)
                : $sql[$measure['key']];
            $query->addSelect(DB::raw($expression.' as '.$this->wrap($query, $measure['key'])));
        }
    }

    /** @param  array<string, mixed>  $measure */
    private function measureSql(Builder $query, ResolvedReport $report, array $measure, QueryDialect $dialect): string
    {
        if (isset($measure['expr'])) {
            return $measure['agg'].'('.$this->formula($query, $report, (array) $measure['expr'], $dialect).')';
        }

        $column = $measure['field'] === null ? null : $this->wrap($query, $report->field($measure['field'])->column());

        return match ($measure['agg']) {
            'count' => 'count('.($column ?? '*').')',
            'count_distinct' => "count(distinct {$column})",
            default => "{$measure['agg']}({$column})",
        };
    }

    /**
     * Geprüfte Formel als SQL (Spalten gekapselt, Zahlen geprüft, Division ohne Fehler bei 0).
     *
     * @param  array<string, mixed>  $expr
     */
    private function formula(Builder $query, ResolvedReport $report, array $expr, QueryDialect $dialect): string
    {
        return ReportExpression::sql($expr, fn (string $key): string => $this->wrap($query, $report->field($key)->column()), $dialect);
    }

    /** Anzeigefelder eines Verknüpfungsfelds mitlesen (bei Gruppen mitgruppiert; sie hängen am Schlüssel). */
    private function selectDisplay(Builder $query, ResolvedField $field, bool $grouped): void
    {
        foreach ($field->displayKeys() as $i => $alias) {
            $column = $field->display['alias'].'.'.$field->display['columns'][$i];
            $query->addSelect($column.' as '.$alias);

            if ($grouped) {
                $query->groupBy($column);
            }
        }
    }

    /** Sortierung; Verknüpfungsfelder nach dem angezeigten Namen, bei Gleichstand nach dem Schlüssel. */
    private function orderBy(Builder $query, ResolvedReport $report, string $key, string $dir): void
    {
        $field = $report->fields[$key] ?? null;

        foreach ($field?->displayKeys() ?? [] as $alias) {
            $query->orderBy($alias, $dir);
        }

        $query->orderBy($key, $dir);
    }

    private function expression(Builder $query, ResolvedField $field, QueryDialect $dialect): Expression|string
    {
        return $field->bucket !== null
            ? $dialect->dateBucket($this->wrap($query, $field->column()), $field->bucket)
            : $field->column();
    }

    private function select(Builder $query, Expression|string $expression, string $key): Expression|string
    {
        return is_string($expression)
            ? $expression.' as '.$key
            : DB::raw($expression->getValue($query->getGrammar()).' as '.$this->wrap($query, $key));
    }

    private function wrap(Builder $query, string $column): string
    {
        return $query->getGrammar()->wrap($column);
    }

    private function filter(Builder $query, ResolvedField $field, string $op, mixed $value, QueryDialect $dialect): void
    {
        $column = $field->column();
        $kind = DefinitionResolver::kind($field);
        $dateTime = $field->type() === FieldType::DateTime;

        match ($op) {
            'is_empty' => $query->where(fn (Builder $q) => $kind === 'text' ? $q->whereNull($column)->orWhere($column, '') : $q->whereNull($column)),
            'not_empty' => $kind === 'text' ? $query->whereNotNull($column)->where($column, '<>', '') : $query->whereNotNull($column),
            'is_true' => $query->where($column, true),
            'is_false' => $query->where($column, false),
            'contains' => $dialect->whereLike($query, $column, '%'.$this->escapeLike((string) $value).'%'),
            'starts_with' => $dialect->whereLike($query, $column, $this->escapeLike((string) $value).'%'),
            'in' => $query->whereIn($column, array_values((array) $value)),
            'neq' => $query->where(fn (Builder $q) => $q->where($column, '<>', $this->value($kind, $value))->orWhereNull($column)),
            default => $kind === 'date'
                ? $this->dateFilter($query, $column, $op, $value, $dateTime)
                : $this->compare($query, $column, $op, $value, $kind),
        };
    }

    private function compare(Builder $query, string $column, string $op, mixed $value, string $kind): void
    {
        match ($op) {
            'between' => $query->whereBetween($column, array_map(fn (mixed $v): mixed => $this->value($kind, $v), array_values((array) $value))),
            default => $query->where($column, self::OPERATORS[$op], $this->value($kind, $value)),
        };
    }

    /**
     * Datumsfilter als halboffene Bereiche [von, bis), damit „gleich 01.03.“ bei Datum und Uhrzeit
     * den ganzen Tag trifft.
     */
    private function dateFilter(Builder $query, string $column, string $op, mixed $value, bool $dateTime): void
    {
        $today = CarbonImmutable::today();
        $day = fn (mixed $v): CarbonImmutable => CarbonImmutable::parse((string) $v)->startOfDay();

        [$from, $until] = match ($op) {
            'eq' => [$day($value), $day($value)->addDay()],
            'gt' => [$day($value)->addDay(), null],
            'gte' => [$day($value), null],
            'lt' => [null, $day($value)],
            'lte' => [null, $day($value)->addDay()],
            'between' => [$day(array_values((array) $value)[0]), $day(array_values((array) $value)[1])->addDay()],
            'today' => [$today, $today->addDay()],
            'this_month' => [$today->startOfMonth(), $today->startOfMonth()->addMonth()],
            'last_month' => [$today->startOfMonth()->subMonth(), $today->startOfMonth()],
            'this_year' => [$today->startOfYear(), $today->startOfYear()->addYear()],
            'last_year' => [$today->startOfYear()->subYear(), $today->startOfYear()],
            'last_days' => [$today->subDays((int) $value - 1), $today->addDay()],
            default => [null, null],
        };

        $format = $dateTime ? 'Y-m-d H:i:s' : 'Y-m-d';

        if ($from !== null) {
            $query->where($column, '>=', $from->format($format));
        }

        if ($until !== null) {
            $query->where($column, '<', $until->format($format));
        }
    }

    private const array OPERATORS = ['eq' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    private function value(string $kind, mixed $value): mixed
    {
        return match ($kind) {
            'number', 'relation' => is_numeric($value) ? $value + 0 : $value,
            default => (string) $value,
        };
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
