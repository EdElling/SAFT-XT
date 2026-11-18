<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DynamicData\DataMenu;
use App\ExternalData\BulkConnector;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\Menu;
use App\Models\Role;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Massenverknüpfung auf der Konsole, z. B. für Datenbanken mit sehr vielen Tabellen (ohne
 * PHP-Zeitlimit des Webservers). Gleiche Logik wie der Reiter „Massenverknüpfung“.
 */
#[Signature('table-connector:connect
    {connection : Name oder ID der Datenbankverbindung}
    {--schema= : Schema (MySQL: Datenbank); Vorgabe: Vorgabeschema der Verbindung}
    {--table=* : Externe Tabellen bzw. Views (mehrfach)}
    {--all : Alle noch nicht angebundenen Tabellen und Views des Schemas}
    {--prefix= : Präfix für die technischen Namen, z. B. erp_}
    {--app= : ID der App (B-107); Vorgabe: die einzige vorhandene}
    {--menu-group= : ID der Menügruppe der App; "none" = kein Menüeintrag; Vorgabe: Gruppe „Daten“ der App}
    {--role=* : Rollen (Name) mit Anzeigerecht und den freigeschalteten Rechten}
    {--inactive : Tabellen inaktiv anlegen}
    {--export : Export erlauben}
    {--create : Anlegen erlauben (soweit möglich)}
    {--update : Bearbeiten erlauben (soweit möglich)}
    {--delete : Löschen erlauben (soweit möglich)}
    {--no-details : Keine Master-Detail-Beziehungen aus Fremdschlüsseln einrichten}')]
#[Description('Bindet viele Tabellen einer externen Datenbank auf einmal an (Massenverknüpfung)')]
class BulkConnectTablesCommand extends Command
{
    public function handle(BulkConnector $connector, DataMenu $menu): int
    {
        $argument = (string) $this->argument('connection');
        $connection = DatabaseConnection::query()->where('name', $argument)->first()
            ?? (ctype_digit($argument) ? DatabaseConnection::query()->find((int) $argument) : null);

        if (! $connection instanceof DatabaseConnection) {
            $this->error("Datenbankverbindung „{$argument}“ nicht gefunden.");

            return self::FAILURE;
        }

        $schema = (string) ($this->option('schema') ?: $connection->schemaName());
        $candidates = $connector->candidates($connection, $schema);
        $tables = $this->option('all')
            ? array_keys(array_filter($candidates, static fn (array $row): bool => $row['connected'] === null))
            : array_values(array_map('strval', (array) $this->option('table')));

        if ($tables === []) {
            $this->error('Keine Tabellen gewählt (--table=… oder --all).');

            return self::FAILURE;
        }

        if (($unknown = array_diff($tables, array_keys($candidates))) !== []) {
            $this->error('Im Schema „'.$schema.'“ nicht gefunden: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $app = App::resolve(filled($this->option('app')) ? (int) $this->option('app') : null);
        $group = $this->option('menu-group');

        if ($group !== null && $group !== 'none' && ! Menu::query()->whereKey((int) $group)->whereNull('route')->whereNull('url')->exists()) {
            $this->error("Menügruppe {$group} nicht gefunden.");

            return self::FAILURE;
        }

        $roles = Role::query()->whereIn('name', (array) $this->option('role'))->pluck('id', 'name');

        if (($missing = array_diff((array) $this->option('role'), $roles->keys()->all())) !== []) {
            $this->error('Rollen nicht gefunden: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $options = [
            'prefix' => (string) $this->option('prefix'),
            'is_active' => ! $this->option('inactive'),
            'export_enabled' => (bool) $this->option('export'),
            'allow_create' => (bool) $this->option('create'),
            'allow_update' => (bool) $this->option('update'),
            'allow_delete' => (bool) $this->option('delete'),
            'app_id' => $app->id,
            'menu_group' => $group === 'none' ? null : ($group ?? $menu->defaultGroup($app->id)),
            'roles' => $roles->values()->map(static fn (mixed $id): int => (int) $id)->all(),
        ];

        $results = [];
        $bar = $this->output->createProgressBar(count($tables));

        foreach (array_chunk($tables, BulkConnector::CHUNK) as $chunk) {
            $results = [...$results, ...$connector->connect($connection, $schema, $chunk, $options)];
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine(2);
        $connected = array_values(array_column(array_filter(
            $results,
            static fn (array $r): bool => in_array($r['status'], [BulkConnector::CONNECTED, BulkConnector::READ_ONLY], true),
        ), 'name'));
        $details = ! $this->option('no-details');
        $finish = $connector->finish($connection, $connected, $details);

        $this->table(
            [__('table_connector.bulk.columns.table'), __('table_connector.bulk.columns.proposed'), __('table_connector.bulk.columns.result'), __('table_connector.bulk.columns.note')],
            array_map(static fn (array $r): array => [$r['table'], $r['name'], __('table_connector.bulk.status.'.$r['status']), $r['message']], $results),
        );

        $counts = array_count_values(array_column($results, 'status'));
        $this->info(__('table_connector.bulk.summary', [
            'connected' => ($counts[BulkConnector::CONNECTED] ?? 0) + ($counts[BulkConnector::READ_ONLY] ?? 0),
            'read_only' => $counts[BulkConnector::READ_ONLY] ?? 0,
            'skipped' => ($counts[BulkConnector::SKIPPED] ?? 0) + ($counts[BulkConnector::FAILED] ?? 0),
            'links' => $finish->links,
        ]));

        if ($details) {
            $this->info(trans_choice('table_connector.bulk.details_summary', $finish->detailCount(), ['count' => $finish->detailCount()]));

            foreach ($finish->details as $table => $fields) {
                $this->line("  {$table}: ".implode(', ', $fields));
            }

            if ($finish->tooMany !== []) {
                $this->warn(__('table_connector.bulk.details_too_many', [
                    'max' => BulkConnector::MAX_AUTO_DETAILS,
                    'tables' => implode(', ', array_map(static fn (string $t, int $n): string => "{$t} ({$n})", array_keys($finish->tooMany), $finish->tooMany)),
                ]));
            }
        }

        return isset($counts[BulkConnector::FAILED]) ? self::FAILURE : self::SUCCESS;
    }
}
