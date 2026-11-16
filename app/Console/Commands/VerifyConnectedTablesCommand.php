<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\ExternalData\SchemaComparison;
use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prüft alle angebundenen Tabellen gegen das aktuelle Schema ihrer externen Datenbank.
 * Ohne --apply nur lesend; Exit-Code 1 bei Abweichungen oder nicht prüfbaren Tabellen (für Cron/Monitoring).
 * Mit --apply werden Abweichungen übernommen; Exit-Code 1 nur, wenn das nicht gelingt.
 */
final class VerifyConnectedTablesCommand extends Command
{
    protected $signature = 'table-connector:verify
        {table?* : Interne Namen; ohne Angabe alle}
        {--apply : Abweichungen übernehmen wie „Neu einlesen“}';

    protected $description = 'Vergleicht angebundene Tabellen mit dem Schema der externen Datenbank';

    public function handle(SchemaComparison $comparison, TableConnectorService $connector): int
    {
        $names = (array) $this->argument('table');
        $tables = CustomTable::query()->external()->with('databaseConnection')
            ->when($names !== [], fn ($q) => $q->whereIn('name', $names))
            ->orderBy('name')
            ->get();

        if ($tables->isEmpty()) {
            $this->components->info(__('table_connector.verify.none'));

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($tables as $table) {
            try {
                $lines = $comparison->compare($table)->lines();
            } catch (Throwable $e) {
                $this->components->error($table->name.': '.__('table_connector.schema.check_failed').' – '.$e->getMessage());
                $failed = true;

                continue;
            }

            if ($lines === []) {
                $this->components->twoColumnDetail($table->name, '<fg=green>'.__('table_connector.list.schema_ok').'</>');

                continue;
            }

            $this->components->twoColumnDetail($table->name, '<fg=yellow>'.trans_choice('table_connector.list.schema_changes', count($lines), ['count' => count($lines)]).'</>');
            $this->components->bulletList($lines);

            if (! $this->option('apply')) {
                $failed = true;

                continue;
            }

            try {
                $notices = $connector->refresh($table);
                $this->components->info(__('table_connector.verify.applied', ['table' => $table->name]));
                $this->components->bulletList($notices);
            } catch (Throwable $e) {
                $this->components->error($table->name.': '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
