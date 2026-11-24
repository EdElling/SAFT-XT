<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use App\Work\WritesRecords;

/**
 * Arbeit „Berechnete Felder neu berechnen“ (Pilot von Fundament 2, F3a; Befehl computed:recompute): Tabellen mit
 * berechneten Feldern blockweise neu berechnen, danach die Tabellen, die über sie summieren. Parameter „tables“:
 * interne Namen; leer = alle Designer-Tabellen mit berechneten Feldern.
 */
final readonly class RecomputeWork implements WorkType, WritesRecords
{
    public const string KEY = 'recompute';

    public function __construct(private Recalculator $recalculator) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.recompute');
    }

    public function describe(array $parameters): string
    {
        $tables = self::tableNames($parameters);

        return $tables === [] ? __('work.recompute.all_tables') : implode(', ', $tables);
    }

    /**
     * Designer-Tabellen mit berechneten Feldern, nach Namen.
     *
     * @param  list<string>  $names  leer = alle
     * @return list<CustomTable>
     */
    public static function tables(array $names = []): array
    {
        return CustomTable::query()->designer()->with('fields')
            ->when($names !== [], fn ($query) => $query->whereIn('name', $names))
            ->orderBy('name')
            ->get()
            ->filter(fn (CustomTable $table): bool => $table->fields->contains(fn (CustomField $field): bool => $field->type === FieldType::Computed))
            ->values()->all();
    }

    public function prepare(array $parameters): WorkPlan
    {
        $order = $this->recalculator->recomputeOrder(self::tables(self::tableNames($parameters)));
        $total = 0;

        foreach ($order as $table) {
            $total += DynamicRecord::forTable($table)->newQuery()->withTrashed()->count();
        }

        return new WorkPlan(['tables' => array_map(fn (CustomTable $table): string => $table->name, $order), 'index' => 0, 'after' => 0, 'changed' => []], $total);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $state = $step->state;
        $names = (array) ($state['tables'] ?? []);
        $index = (int) ($state['index'] ?? 0);
        $changed = (array) ($state['changed'] ?? []);
        $name = $names[$index] ?? null;
        $table = $name === null ? null : CustomTable::query()->designer()->with('fields')->where('name', $name)->first();
        $count = 0;

        // Eine inzwischen gelöschte Tabelle wird übersprungen.
        if ($table !== null) {
            $block = $this->recalculator->recomputeBlock($table, (int) ($state['after'] ?? 0));
            $count = $block['count'];
            $changed[$name] = (int) ($changed[$name] ?? 0) + $block['changed'];
            $state['after'] = $block['last_id'];
        }

        if ($table === null || $count < Recalculator::BLOCK) {
            $index++;
            $state['after'] = 0;
        }

        if ($index >= count($names)) {
            $total = array_sum($changed);

            return StepOutcome::finished(
                trans_choice('tables.formula.recompute_changed', $total, ['count' => $total]),
                ['changed' => $changed],
                $count,
            );
        }

        return StepOutcome::continue([...$state, 'index' => $index, 'changed' => $changed], $count);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    private static function tableNames(array $parameters): array
    {
        return array_values(array_map('strval', (array) ($parameters['tables'] ?? [])));
    }
}
