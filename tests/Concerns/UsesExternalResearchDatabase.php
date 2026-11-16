<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\ExternalData\ConnectionDriver;
use App\Models\DatabaseConnection;
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
