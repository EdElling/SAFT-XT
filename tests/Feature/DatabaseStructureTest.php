<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DynamicData\DynamicDataNamespace;
use App\Services\DynamicSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * Voraussetzungen für Cluster wie MariaDB Galera: jede Tabelle hat einen Primärschlüssel, unter
 * MySQL/MariaDB liegen alle Tabellen in InnoDB. Prüft die Tabellen von SAFT und die des
 * Table-Designers, damit eine künftige Migration das nicht unbemerkt bricht.
 */
class DatabaseStructureTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    /** Auch app_data in der Test-Transaktion (PostgreSQL: die Tabelle des Tests wird zurückgerollt). */
    protected array $connectionsToTransact = [null, 'app_data'];

    public function test_every_table_has_a_primary_key_and_uses_innodb(): void
    {
        $this->seedAdminStructure();
        app(DynamicSchemaService::class)->createTable(['name' => 'pruefung', 'label' => 'Prüfung'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_unique' => true],
        ]);

        $namespace = app(DynamicDataNamespace::class);
        $sets = [
            'SAFT' => [Schema::getConnection(), null],
            'Table-Designer' => [$namespace->connection(), $namespace->name()],
        ];
        $checked = 0;

        foreach ($sets as $label => [$connection, $schema]) {
            $builder = $connection->getSchemaBuilder();
            $tables = $schema === null ? $builder->getTables($builder->getCurrentSchemaListing()) : $builder->getTables($schema);

            foreach ($tables as $table) {
                $name = $schema === null ? (string) $table['name'] : $schema.'.'.$table['name'];
                $indexes = $builder->getIndexes($schema === null ? (string) ($table['schema_qualified_name'] ?? $table['name']) : $name);

                $this->assertTrue(collect($indexes)->contains('primary', true), "{$label}: Tabelle {$name} ohne Primärschlüssel.");

                if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $this->assertSame('innodb', strtolower((string) ($table['engine'] ?? '')), "{$label}: Tabelle {$name} nicht InnoDB.");
                }

                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked);
    }
}
