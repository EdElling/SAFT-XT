<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomTable;
use Illuminate\Console\Command;

/**
 * Berechnete Felder aller (oder genannter) Designer-Tabellen neu berechnen, z. B. nach Änderungen
 * an SAFT vorbei direkt in der Datenbank.
 */
final class RecomputeComputedFieldsCommand extends Command
{
    protected $signature = 'computed:recompute {table?* : Interne Namen; ohne Angabe alle Tabellen mit berechneten Feldern}';

    protected $description = 'Berechnet die berechneten Felder des Table-Designers neu';

    public function handle(Recalculator $recalculator): int
    {
        $names = (array) $this->argument('table');
        $tables = CustomTable::query()->designer()->with('fields')
            ->when($names !== [], fn ($q) => $q->whereIn('name', $names))
            ->orderBy('name')
            ->get()
            ->filter(fn (CustomTable $t): bool => $t->fields->contains(fn ($f): bool => $f->type === FieldType::Computed));

        if ($tables->isEmpty()) {
            $this->components->info(__('tables.formula.recompute_none'));

            return self::SUCCESS;
        }

        foreach ($tables as $table) {
            $changed = $recalculator->recomputeTable($table);
            $this->components->twoColumnDetail($table->name, trans_choice('tables.formula.recompute_changed', $changed, ['count' => $changed]));
        }

        return self::SUCCESS;
    }
}
