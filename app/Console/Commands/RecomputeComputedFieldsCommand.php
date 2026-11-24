<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WorkItem;
use App\Work\Types\RecomputeWork;
use App\Work\WorkService;
use Illuminate\Console\Command;

/**
 * Berechnete Felder aller (oder genannter) Designer-Tabellen neu berechnen, z. B. nach Änderungen
 * an SAFT vorbei direkt in der Datenbank. Läuft als Arbeit (Fundament 2, F3a): ohne --background treibt der Befehl
 * sie selbst bis zum Ende voran, mit --background übernimmt der Scheduler (work:run).
 */
final class RecomputeComputedFieldsCommand extends Command
{
    protected $signature = 'computed:recompute
        {table?* : Interne Namen; ohne Angabe alle Tabellen mit berechneten Feldern}
        {--background : Nur starten; der Scheduler (work:run) rechnet im Hintergrund}';

    protected $description = 'Berechnet die berechneten Felder des Table-Designers neu';

    public function handle(WorkService $work): int
    {
        $names = array_values(array_map('strval', (array) $this->argument('table')));

        if (RecomputeWork::tables($names) === []) {
            $this->components->info(__('tables.formula.recompute_none'));

            return self::SUCCESS;
        }

        if ($this->option('background')) {
            $item = $work->start(RecomputeWork::KEY, ['tables' => $names]);
            $this->components->info(__('work.started', ['id' => $item->uuid]));

            return self::SUCCESS;
        }

        $item = $work->startAndDrive(RecomputeWork::KEY, ['tables' => $names]);

        if ($item->status !== WorkItem::DONE) {
            $this->components->error((string) $item->message);

            return self::FAILURE;
        }

        foreach ((array) ($item->result['changed'] ?? []) as $table => $changed) {
            $this->components->twoColumnDetail((string) $table, trans_choice('tables.formula.recompute_changed', (int) $changed, ['count' => (int) $changed]));
        }

        return self::SUCCESS;
    }
}
