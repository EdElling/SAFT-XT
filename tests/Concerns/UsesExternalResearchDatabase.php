<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\ExternalData\ConnectionDriver;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DataSnapshot;
use App\Models\WorkItem;
use App\Work\Types\SnapshotRefreshWork;
use App\Work\WorkService;
use Database\Seeders\ExternalDemoTablesSeeder;
use Database\Seeders\ExternalResearchDatabase;

/** Externe Forschungsdatenbank (Schema forschung) und eine Verbindung dorthin. */
trait UsesExternalResearchDatabase
{
    protected function externalDatabaseName(): string
    {
        return (string) env('EXT_DB_DATABASE');
    }

    protected function prepareExternalDatabase(): ExternalResearchDatabase
    {
        $fixture = ExternalResearchDatabase::on($this->externalDatabaseName());

        if (! $fixture->isCurrent()) {
            $fixture->reset();
        }

        return $fixture;
    }

    /** Die Demo-Konfiguration der angebundenen Tabellen (wie external-demo:install). */
    protected function connectResearchDemoTables(DatabaseConnection $connection): void
    {
        app(ExternalDemoTablesSeeder::class)->install($connection);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    /** Die Tabelle gilt als „wird gerade aktualisiert“: Stand „läuft“ mit wartender Arbeit (bis WorkService::cancel()). */
    protected function busySnapshot(CustomTable $table): WorkItem
    {
        $snapshot = DataSnapshot::create(['custom_table_id' => $table->id, 'status' => DataSnapshot::RUNNING, 'started_at' => now()]);
        $item = app(WorkService::class)->start(SnapshotRefreshWork::KEY, ['table_id' => $table->id, 'snapshot_id' => $snapshot->id]);
        $snapshot->update(['work_item_id' => $item->id]);

        return $item;
    }

    protected function researchConnection(array $overrides = []): DatabaseConnection
    {
        $own = config('database.connections.pgsql');

        return DatabaseConnection::create([
            'name' => 'Forschung',
            'driver' => ConnectionDriver::Pgsql,
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => $this->externalDatabaseName(),
            'username' => $own['username'],
            'password' => $own['password'],
            'default_schema' => ExternalResearchDatabase::SCHEMA,
            ...$overrides,
        ]);
    }
}
