<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\DetailRelations;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\SchemaVerifier;
use App\ExternalData\BulkConnector;
use App\ExternalData\ColumnInfo;
use App\ExternalData\ConnectionDriver;
use App\ExternalData\ConnectionRegistry;
use App\ExternalData\ConnectionTester;
use App\ExternalData\SchemaComparison;
use App\ExternalData\SchemaInspector;
use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use Database\Seeders\SqlServerResearchDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;
use Throwable;

/**
 * Eine externe SQL-Server-Datenbank im Table-Connector. Grundlage ist die SQL-Server-Fassung der
 * Forschungsdatenbank (MSSQL_*, EXT_MSSQL_DB_DATABASE). Ohne pdo_sqlsrv oder erreichbaren Server
 * werden die Tests übersprungen.
 */
class SqlServerSourceTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private SqlServerResearchDatabase $fixture;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();

        try {
            $this->fixture = SqlServerResearchDatabase::on((string) env('EXT_MSSQL_DB_DATABASE', 'SAFT2_ext_test'));
            // Die Tests schreiben in die externe Testdatenbank: vor jedem Test frisch aufbauen.
            $this->fixture->reset();
        } catch (QueryException|RuntimeException $e) {
            $this->markTestSkipped('Kein SQL Server erreichbar (MSSQL_*) oder pdo_sqlsrv fehlt: '.($e->getPrevious()?->getMessage() ?? $e->getMessage()));
        }

        $this->connection = $this->sqlServerConnection();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            app(ConnectionRegistry::class)->forget($this->connection);
        }

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function sqlServerConnection(array $overrides = []): DatabaseConnection
    {
        $own = config('database.connections.sqlsrv');

        return DatabaseConnection::create([
            'name' => 'Forschung SQL Server',
            'driver' => ConnectionDriver::Sqlsrv,
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => $this->fixture->database(),
            'username' => $own['username'],
            'password' => $own['password'],
            'default_schema' => SqlServerResearchDatabase::SCHEMA,
            'is_read_only' => false,
            ...$overrides,
        ]);
    }

    private function service(): TableConnectorService
    {
        return app(TableConnectorService::class);
    }

    private function connect(string $table, bool $writable = false): CustomTable
    {
        $connected = $this->service()->connect($this->connection, $this->connection->schemaName(), $table, ['name' => 'ms_'.$table]);

        return ($writable ? $this->service()->update($connected, ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true]) : $connected)->fresh('fields');
    }

    /** @return array<string, ColumnInfo> */
    private function columns(string $table): array
    {
        return collect(app(SchemaInspector::class)->columns($this->connection, $this->connection->schemaName(), $table))->keyBy('name')->all();
    }

    public function test_driver_defaults(): void
    {
        $this->assertSame(1433, ConnectionDriver::Sqlsrv->defaultPort());
        $this->assertSame('require', ConnectionDriver::Sqlsrv->defaultSslMode());
        $this->assertSame('dbo', ConnectionDriver::Sqlsrv->defaultSchema('SAFT2_ext'));
        $this->assertTrue(ConnectionDriver::Sqlsrv->isAvailable());
        $this->assertFalse(ConnectionDriver::Sqlsrv->enforcesReadOnly());

        // Ohne Eintrag gilt dbo; eine ungültige TLS-Einstellung wird beim Speichern korrigiert.
        $other = $this->sqlServerConnection(['name' => 'Ohne Schema', 'default_schema' => null, 'ssl_mode' => 'prefer']);
        $this->assertSame('dbo', $other->schemaName());
        $this->assertSame('require', $other->ssl_mode);
    }

    public function test_connection_test_lists_schemas_without_system_schemas(): void
    {
        $result = app(ConnectionTester::class)->test($this->connection);

        $this->assertTrue($result->successful, $result->message);
        $this->assertStringContainsString('Schema „forschung“: 7 Tabellen, 1 Views', $result->message);
        $this->assertContains('forschung', $result->schemas);
        $this->assertContains('dbo', $result->schemas);
        $this->assertNotContains('sys', $result->schemas);
        $this->assertNotContains('db_owner', $result->schemas);
        $this->assertNotContains('INFORMATION_SCHEMA', $result->schemas);
    }

    public function test_wrong_password_and_unknown_database_are_explained(): void
    {
        $placeholders = ['host' => $this->connection->host, 'port' => $this->connection->port, 'database' => $this->connection->database, 'seconds' => $this->connection->connect_timeout];
        $this->connection->update(['password' => 'Kein-Passwort-4711']);

        $result = app(ConnectionTester::class)->test($this->connection);
        $this->assertFalse($result->successful);
        $this->assertStringContainsString(__('db_connections.errors.auth', $placeholders), $result->message);
        $this->assertStringNotContainsString('Kein-Passwort-4711', $result->message);

        $this->connection->update(['password' => config('database.connections.sqlsrv.password'), 'database' => 'Gibt_es_nicht_ext']);
        $result = app(ConnectionTester::class)->test($this->connection);
        $this->assertStringContainsString(__('db_connections.errors.unknown_database', [...$placeholders, 'database' => 'Gibt_es_nicht_ext']), $result->message);
    }

    public function test_tables_keys_and_column_types(): void
    {
        $tables = collect(app(SchemaInspector::class)->tables($this->connection, $this->connection->schemaName()))->keyBy('name');

        $this->assertSame(['importprotokoll', 'laender', 'organisationen', 'personen', 'projekt_uebersicht', 'projekte', 'publikation_autoren', 'publikationen'], $tables->keys()->all());
        $this->assertSame('view', $tables['projekt_uebersicht']->kind);
        $this->assertEqualsCanonicalizing(['publikation_id', 'person_id'], $tables['publikation_autoren']->keyColumns);
        $this->assertSame([], $tables['importprotokoll']->keyColumns);

        $people = $this->columns('personen');
        $this->assertTrue($people['id']->generated, 'IDENTITY');
        $this->assertTrue($people['vollname']->generated, 'berechnete Spalte');
        $this->assertSame(FieldType::String, $people['vorname']->type);
        $this->assertSame(['length' => 100], $people['vorname']->options, 'nvarchar(100): Zeichen, nicht Bytes.');
        $this->assertTrue($people['email']->unique, 'gefilterter eindeutiger Index');
        $this->assertSame(FieldType::DateTime, $people['erstellt_am']->type);
        $this->assertSame(ColumnInfo::UNSUPPORTED_TYPE, $people['foto']->unsupported);

        $projects = $this->columns('projekte');
        $this->assertSame(FieldType::Decimal, $projects['budget']->type);
        $this->assertSame(['precision' => 12, 'scale' => 2], $projects['budget']->options);
        $this->assertSame(FieldType::Text, $projects['metadaten']->type);
        $this->assertSame(FieldType::Date, $projects['beginn']->type);
        $this->assertSame(FieldType::Boolean, $this->columns('organisationen')['aktiv']->type);
        $this->assertSame(FieldType::Text, $this->columns('publikationen')['titel']->type);
        $this->assertTrue($this->columns('publikationen')['id']->hasUuidDefault());
        $this->assertTrue($this->columns('importprotokoll')['zeitpunkt']->textOnly, 'datetimeoffset nur als Text');

        $keys = collect(app(SchemaInspector::class)->foreignKeys($this->connection, 'forschung', 'projekte'))->keyBy('column');
        $this->assertSame('personen', $keys['projektleiter_id']->foreignTable);
    }

    public function test_checks_become_rules_and_value_lists(): void
    {
        $organisations = $this->connect('organisationen');
        $projects = $this->connect('projekte');
        $publications = $this->connect('publikationen');

        $this->assertSame(['hochschule', 'forschungsinstitut', 'unternehmen', 'behoerde'], $organisations->fields->firstWhere('name', 'typ')->optionsArray()[TableConnectorService::DB_VALUES]);
        $this->assertSame(['geplant', 'laufend', 'abgeschlossen', 'abgebrochen'], $projects->fields->firstWhere('name', 'status')->optionsArray()[TableConnectorService::DB_VALUES]);
        $this->assertEquals(['min' => 1900, 'max' => 2100], $publications->fields->firstWhere('name', 'jahr')->optionsArray()[TableConnectorService::DB_RULES]);
        $this->assertEquals(['min' => 0], $projects->fields->firstWhere('name', 'budget')->optionsArray()[TableConnectorService::DB_RULES]);
        $this->assertSame(['ende'], array_column(array_column((array) $projects->validation_rules, 'left'), 'field'));

        $stored = collect((array) $organisations->check_constraints)->keyBy('name');
        $this->assertFalse($stored['organisationen_webseite_check']['translated'], 'Muster prüft nur die Datenbank.');
        $this->assertTrue(app(SchemaComparison::class)->compare($projects)->isEmpty());
    }

    public function test_reading_rows_and_views(): void
    {
        foreach (['personen' => 20, 'publikation_autoren' => 61, 'importprotokoll' => 5, 'projekt_uebersicht' => 12] as $table => $count) {
            $this->assertSame($count, DynamicRecord::forTable($this->connect($table))->newQuery()->count(), $table);
        }

        $people = DynamicRecord::forTableName('ms_personen');
        $this->assertSame('Anna Keller', $people->newQuery()->orderBy('id')->first()?->getAttribute('vollname'));
        $this->assertSame('Rahel Bühler', $people->newQuery()->where('nachname', 'Bühler')->value('vollname'), 'Umlaute in Parametern');
        // Seitenweise wie die Liste (OFFSET/FETCH).
        $this->assertCount(5, $people->newQuery()->orderBy('nachname')->forPage(2, 5)->get());
    }

    public function test_writing_with_identity_uuid_and_composite_keys(): void
    {
        $organisations = $this->connect('organisationen', writable: true);
        $organisation = DynamicRecord::forTable($organisations)->create(['name' => 'Hochschule Luzern', 'land' => 'CH', 'typ' => 'hochschule']);
        $this->assertSame(9, (int) $organisation->getKey(), 'IDENTITY kommt zurück.');
        $this->assertTrue((bool) $this->fixture->connection()->table('forschung.organisationen')->where('id', 9)->value('aktiv'), 'Vorgabewert der Datenbank.');

        $publications = $this->connect('publikationen', writable: true);
        $publication = DynamicRecord::forTable($publications)->create(['titel' => 'Neue Studie', 'jahr' => 2026, 'typ' => 'artikel']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $publication->getKey());
        $this->assertSame('Neue Studie', $this->fixture->connection()->table('forschung.publikationen')->where('id', $publication->getKey())->value('titel'));

        $publication->update(['titel' => 'Neue Studie (überarbeitet)']);
        $this->assertSame('Neue Studie (überarbeitet)', $this->fixture->connection()->table('forschung.publikationen')->where('id', $publication->getKey())->value('titel'));

        $authors = $this->connect('publikation_autoren', writable: true);
        DynamicRecord::forTable($authors)->create(['publikation_id' => $publication->getKey(), 'person_id' => 1, 'reihenfolge' => 1]);
        $this->assertSame(1, $this->fixture->connection()->table('forschung.publikation_autoren')->where('publikation_id', $publication->getKey())->count());

        $organisation->delete();
        $this->assertFalse($this->fixture->connection()->table('forschung.organisationen')->where('id', 9)->exists());
    }

    public function test_check_violation_comes_from_the_database(): void
    {
        $organisations = $this->connect('organisationen', writable: true);

        try {
            DynamicRecord::forTable($organisations)->create(['name' => 'X', 'land' => 'CH', 'typ' => 'verein']);
            $this->fail('CHECK-Regel nicht gegriffen.');
        } catch (QueryException $e) {
            $this->assertSame(547, (int) $e->errorInfo[1]);
        }
    }

    public function test_read_only_is_enforced_by_saft(): void
    {
        $countries = $this->connect('laender', writable: true);
        $this->assertTrue($countries->supports('create'));

        $this->connection->update(['is_read_only' => true]);
        $countries = $countries->fresh(['databaseConnection']);

        $this->assertFalse($countries->supports('create'), 'Nur lesen sperrt auch freigeschaltete Tabellen.');
        $this->assertFalse($countries->isWritable());

        try {
            DynamicRecord::forTable($countries)->create(['code' => 'IT', 'name' => 'Italien']);
            $this->fail('Schreiben trotz „nur lesen“.');
        } catch (Throwable) {
            $this->assertFalse($this->fixture->connection()->table('forschung.laender')->where('code', 'IT')->exists());
        }

        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_demo_command_creates_connection_and_links_tables(): void
    {
        $this->artisan('external-demo:install', ['--driver' => 'sqlsrv', '--database' => $this->fixture->database(), '--name' => 'Forschung (SQL Server)'])
            ->expectsOutputToContain('bereits vorhanden')
            ->assertSuccessful();

        $connection = DatabaseConnection::query()->where('name', 'Forschung (SQL Server)')->sole();
        $this->assertSame(ConnectionDriver::Sqlsrv, $connection->driver);
        $this->assertSame('forschung', $connection->schemaName());
        $this->assertTrue($connection->is_read_only);
        $this->assertSame(6, CustomTable::query()->where('database_connection_id', $connection->id)->count());
        $this->assertSame(20, DynamicRecord::forTableName('fdb_personen')->newQuery()->count());

        app(ConnectionRegistry::class)->forget($connection);
    }

    public function test_bulk_link_of_all_tables(): void
    {
        $bulk = app(BulkConnector::class);
        $schema = $this->connection->schemaName();
        $tables = array_keys($bulk->candidates($this->connection, $schema));

        $results = collect($bulk->connect($this->connection, $schema, $tables, ['prefix' => 's_', 'allow_create' => true, 'menu_group' => null]))->keyBy('table');
        $finish = $bulk->finish($this->connection, $results->pluck('name')->all(), details: true);

        $this->assertCount(8, $results);
        $this->assertSame(BulkConnector::CONNECTED, $results['publikationen']['status'], 'UUID-Schlüssel ist schreibbar.');
        $this->assertSame(BulkConnector::READ_ONLY, $results['projekt_uebersicht']['status']);
        $this->assertSame(BulkConnector::READ_ONLY, $results['importprotokoll']['status'], 'Ohne Primärschlüssel nur lesbar.');
        $this->assertSame('s_personen', CustomTable::query()->where('name', 's_projekte')->sole()->fields()->where('name', 'projektleiter_id')->sole()->optionsArray()['table']);

        // Master-Detail aus den Fremdschlüsseln.
        $details = DetailRelations::configuredFor(CustomTable::query()->where('name', 's_personen')->sole())->map(fn ($f): string => $f->child->name.'.'.$f->column())->all();
        $this->assertSame(['s_projekte.projektleiter_id', 's_publikation_autoren.person_id'], $details);
        $this->assertGreaterThanOrEqual(6, $finish->detailCount());
    }
}
