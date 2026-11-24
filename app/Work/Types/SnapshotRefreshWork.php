<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\Snapshots\SnapshotService;
use App\Models\CustomTable;
use App\Models\DataSnapshot;
use App\Models\User;
use App\Work\CleansUp;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use App\Work\WritesRecords;
use Illuminate\Support\Facades\Auth;

/**
 * Arbeit „Datenstand ziehen“ (Fundament 2, F3b): eine angebundene Tabelle blockweise in ihre Kopie (ds_<tabelle>)
 * kopieren. prepare() ergänzt die Kopie um neue Spalten (DDL) und zählt die Quelle; jeder Schritt kopiert Blöcke, bis
 * sein Zeitbudget knapp wird (mindestens einen). Cursor sind die Schlüsselwerte der letzten Zeile (Keyset); Tabellen
 * ohne Schlüssel werden in einem Durchgang kopiert. Schreibender Schritt: Zeilen und Fortschritt sind eine Transaktion,
 * ein abgebrochener Prozess hinterlässt keine doppelten Zeilen. Der letzte Schritt setzt den Stand auf „vollständig“,
 * protokolliert und setzt die Aufbewahrung durch; abort() verwirft den Stand, der vorherige bleibt gültig.
 *
 * Parameter: table_id, snapshot_id (Stand „läuft“, angelegt von SnapshotService::start()/refresh()).
 */
final readonly class SnapshotRefreshWork implements CleansUp, WorkType, WritesRecords
{
    public const string KEY = 'snapshot_refresh';

    public function __construct(private SnapshotService $snapshots) {}

    /** Zeilen je Block. */
    public static function chunk(): int
    {
        return max(1, (int) config('reports.snapshot_chunk', 1000));
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.snapshot_refresh');
    }

    public function describe(array $parameters): string
    {
        $table = CustomTable::query()->with('databaseConnection')->find((int) ($parameters['table_id'] ?? 0));

        if ($table === null) {
            return '';
        }

        $connection = $table->databaseConnection;

        return $table->label.' ('.($connection === null ? '?' : $connection->name).')';
    }

    public function prepare(array $parameters): WorkPlan
    {
        [$table] = $this->resolve($parameters);

        return new WorkPlan(['columns' => $this->snapshots->ensureTable($table), 'after' => null, 'rows' => 0], $this->snapshots->countSource($table));
    }

    public function step(WorkStep $step): StepOutcome
    {
        [$table, $snapshot] = $this->resolve($step->parameters);
        $columns = array_values(array_map('strval', (array) ($step->state['columns'] ?? [])));
        $after = isset($step->state['after']) && is_array($step->state['after']) ? $step->state['after'] : null;
        $rows = (int) ($step->state['rows'] ?? 0);
        $max = max(0, (int) config('reports.snapshot_max_rows'));
        $done = 0;

        do {
            $block = $this->snapshots->copyBlock($table, $snapshot, $columns, $after, self::chunk());
            $rows += $block['rows'];
            $done += $block['rows'];
            $after = $block['after'];

            if ($max > 0 && $rows > $max) {
                throw new BusinessRuleException(__('snapshots.errors.too_large', ['max' => number_format($max, 0, ',', '\'')]), 'snapshot.too_large');
            }

            if ($block['complete']) {
                $user = Auth::user();
                $this->snapshots->complete($table, $snapshot, $rows, $user instanceof User ? $user : null);

                return StepOutcome::finished(
                    __('snapshots.refreshed', ['rows' => number_format($rows, 0, ',', '\'')]),
                    ['snapshot_id' => $snapshot->id, 'rows' => $rows],
                    $done,
                );
            }
        } while ($step->remaining() > 1);

        return StepOutcome::continue([...$step->state, 'after' => $after, 'rows' => $rows], $done);
    }

    /** Fehler oder Abbruch: den halben Stand verwerfen; der vorherige bleibt gültig. */
    public function abort(WorkStep $step, string $status, string $message): void
    {
        $snapshot = DataSnapshot::query()->find((int) ($step->parameters['snapshot_id'] ?? 0));

        if ($snapshot !== null && $snapshot->isRunning()) {
            $this->snapshots->fail($snapshot, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: CustomTable, 1: DataSnapshot}
     */
    private function resolve(array $parameters): array
    {
        $table = CustomTable::query()->external()->find((int) ($parameters['table_id'] ?? 0))
            ?? throw new BusinessRuleException(__('tasks.missing_table'));
        $snapshot = DataSnapshot::query()->where('custom_table_id', $table->id)->find((int) ($parameters['snapshot_id'] ?? 0));

        if ($snapshot === null || ! $snapshot->isRunning()) {
            throw new BusinessRuleException(__('snapshots.errors.failed'), 'snapshot.failed');
        }

        return [$table, $snapshot];
    }
}
