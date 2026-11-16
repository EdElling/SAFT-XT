<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\Schema\FieldType;
use App\ExternalData\ConnectionDriver;
use App\ExternalData\ConnectionRegistry;
use App\ExternalData\SchemaInspector;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;

/**
 * Dieselben Tests wie für MySQL gegen eine externe MariaDB (MARIADB_*, EXT_MARIADB_DB_DATABASE),
 * dazu die Eigenheiten von MariaDB. Ohne erreichbaren Server werden sie übersprungen.
 */
class MariaDbSourceTest extends MySqlSourceTest
{
    protected function server(): string
    {
        return 'mariadb';
    }

    protected function driver(): ConnectionDriver
    {
        return ConnectionDriver::Mariadb;
    }

    protected function fixtureDatabase(): string
    {
        return (string) env('EXT_MARIADB_DB_DATABASE', 'SAFT2_ext_test');
    }

    protected function checkViolationCode(): int
    {
        return 4025;
    }

    public function test_demo_command_creates_connection_and_links_tables(): void
    {
        $this->artisan('external-demo:install', ['--driver' => 'mariadb', '--database' => $this->fixtureDatabase(), '--name' => 'Forschung (MariaDB)'])
            ->expectsOutputToContain('bereits vorhanden')
            ->assertSuccessful();

        $connection = DatabaseConnection::query()->where('name', 'Forschung (MariaDB)')->sole();
        $this->assertSame(ConnectionDriver::Mariadb, $connection->driver);
        $this->assertSame($this->fixtureDatabase(), $connection->schemaName());
        $this->assertTrue($connection->is_read_only);
        $this->assertSame(6, CustomTable::query()->where('database_connection_id', $connection->id)->count());
        $this->assertSame(20, DynamicRecord::forTableName('fdb_personen')->newQuery()->count());

        app(ConnectionRegistry::class)->forget($connection);
    }

    public function test_mariadb_types_json_and_uuid(): void
    {
        $connection = DatabaseConnection::query()->where('driver', ConnectionDriver::Mariadb)->sole();
        $inspector = app(SchemaInspector::class);
        $schema = $connection->schemaName();

        $projects = collect($inspector->columns($connection, $schema, 'projekte'))->keyBy('name');
        $this->assertSame('json', $projects['metadaten']->externalType, 'longtext mit json_valid() gilt als JSON');
        $this->assertTrue($projects['metadaten']->textOnly);

        $checks = array_column($inspector->checkConstraints($connection, $schema, 'projekte'), 'name');
        $this->assertNotContains('metadaten', $checks, 'Die json_valid()-Regel erscheint nicht als Datenbankregel.');

        $publications = collect($inspector->columns($connection, $schema, 'publikationen'))->keyBy('name');
        $this->assertSame('uuid', $publications['id']->externalType);
        $this->assertSame(FieldType::String, $publications['id']->type);
        $this->assertSame(['length' => 36], $publications['id']->options);
    }
}
