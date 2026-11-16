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
use Database\Seeders\MySqlResearchDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;
use Throwable;

/**
 * Eine externe MySQL-Datenbank im Table-Connector, unabhängig davon, welche Datenbank SAFT selbst
 * nutzt. Grundlage ist die MySQL-Fassung der Forschungsdatenbank (MYSQL_*, EXT_MYSQL_DB_DATABASE).
 * MariaDbSourceTest führt dieselben Tests gegen MariaDB aus.
 */
class MySqlSourceTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private MySqlResearchDatabase $fixture;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();

        try {
            $this->fixture = MySqlResearchDatabase::on($this->fixtureDatabase(), $this->server());
        } catch (QueryException $e) {
            $this->markTestSkipped('Kein '.$this->driver()->label().'-Server erreichbar: '.$e->getPrevious()?->getMessage());
        }

        // Die Tests schreiben in die externe Testdatenbank: vor jedem Test frisch aufbauen.
        $this->fixture->reset();
        $this->connection = $this->mysqlConnection();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            app(ConnectionRegistry::class)->forget($this->connection);
        }

        parent::tearDown();
    }

    /** Verbindung in config/database.php mit den Zugangsdaten des Servers. */
    protected function server(): string
    {
        return 'mysql';
    }

    protected function driver(): ConnectionDriver
    {
        return ConnectionDriver::Mysql;
    }

    protected function fixtureDatabase(): string
    {
        return (string) env('EXT_MYSQL_DB_DATABASE', 'SAFT2_ext_test');
    }

    /** Fehlernummer bei verletzter CHECK-Regel (MySQL 3819, MariaDB 4025). */
    protected function checkViolationCode(): int
    {
        return 3819;
    }

    /** @param  array<string, mixed>  $overrides */
    private function mysqlConnection(array $overrides = []): DatabaseConnection
    {
        $own = config('database.connections.'.$this->server());

        return DatabaseConnection::create([
            'name' => 'Forschung '.$this->driver()->label(),
            'driver' => $this->driver(),
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => $this->fixture->schema(),
            'username' => $own['username'],
            'password' => $own['password'],
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
        $connected = $this->service()->connect($this->connection, $this->connection->schemaName(), $table, ['name' => 'my_'.$table]);

        return ($writable ? $this->service()->update($connected, ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true]) : $connected)->fresh('fields');
    }

    /** @return array<string, ColumnInfo> */
    private function columns(string $table): array
    {
        return collect(app(SchemaInspector::class)->columns($this->connection, $this->connection->schemaName(), $table))->keyBy('name')->all();
    }

    public function test_connection_test_offers_databases_as_schemas(): void
    {
        $result = app(ConnectionTester::class)->test($this->connection);

        $this->assertTrue($result->successful, $result->message);
        $this->assertSame($this->fixture->schema(), $this->connection->schemaName(), 'Ohne Eintrag ist das Schema die Datenbank.');
        $this->assertContains($this->fixture->schema(), $result->schemas);
        $this->assertNotContains('mysql', $result->schemas);
        $this->assertNotContains('information_schema', $result->schemas);
    }

    public function test_wrong_password_is_explained(): void
    {
        $this->connection->update(['password' => 'Kein-Passwort-4711']);

        $result = app(ConnectionTester::class)->test($this->connection);

        $this->assertFalse($result->successful);
        $this->assertStringContainsString(__('db_connections.errors.auth', ['host' => $this->connection->host, 'port' => $this->connection->port, 'database' => $this->connection->database, 'seconds' => $this->connection->connect_timeout]), $result->message);
        $this->assertStringNotContainsString('Kein-Passwort-4711', $result->message);
    }

    public function test_tables_keys_and_column_types(): void
    {
        $tables = collect(app(SchemaInspector::class)->tables($this->connection, $this->connection->schemaName()))->keyBy('name');

        $this->assertSame(['importprotokoll', 'laender', 'organisationen', 'personen', 'projekt_uebersicht', 'projekte', 'publikation_autoren', 'publikationen'], $tables->keys()->all());
        $this->assertSame(['publikation_id', 'person_id'], $tables['publikation_autoren']->keyColumns);
        $this->assertSame([], $tables['importprotokoll']->keyColumns);
        $this->assertSame('Forschungsprojekte mit Leitung und Partner', $tables['projekte']->comment);

        $people = $this->columns('personen');
        $this->assertTrue($people['id']->generated, 'AUTO_INCREMENT');
        $this->assertTrue($people['vollname']->generated, 'berechnete Spalte');
        $this->assertSame(ColumnInfo::UNSUPPORTED_TYPE, $people['foto']->unsupported);

        $projects = $this->columns('projekte');
        $this->assertSame(FieldType::Decimal, $projects['budget']->type);
        $this->assertSame(FieldType::String, $projects['status']->type);
        $this->assertTrue($projects['metadaten']->textOnly);
        $this->assertSame(FieldType::Boolean, $this->columns('organisationen')['aktiv']->type);
        $this->assertTrue($this->columns('publikationen')['id']->hasUuidDefault());
    }

    public function test_checks_and_enum_become_rules_and_value_lists(): void
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

        // Geänderte enum-Werte meldet der Abgleich wie eine geänderte CHECK-Regel.
        $this->fixture->connection()->statement("alter table projekte modify status enum('geplant','laufend','abgeschlossen','abgebrochen','pausiert') not null default 'geplant'");
        $this->assertSame([['name' => 'status (enum)', 'state' => 'changed']], app(SchemaComparison::class)->compare($projects->fresh())->checks);
    }

    public function test_reading_rows_and_views(): void
    {
        foreach (['personen' => 20, 'publikation_autoren' => 61, 'importprotokoll' => 5, 'projekt_uebersicht' => 12] as $table => $count) {
            $this->assertSame($count, DynamicRecord::forTable($this->connect($table))->newQuery()->count(), $table);
        }

        $first = DynamicRecord::forTableName('my_personen')->newQuery()->orderBy('id')->first();
        $this->assertSame('Anna Keller', $first?->getAttribute('vollname'));
    }

    public function test_writing_with_auto_increment_uuid_and_composite_keys(): void
    {
        $organisations = $this->connect('organisationen', writable: true);
        $organisation = DynamicRecord::forTable($organisations)->create(['name' => 'Hochschule Luzern', 'land' => 'CH', 'typ' => 'hochschule']);
        $this->assertSame(9, (int) $organisation->getKey(), 'AUTO_INCREMENT kommt zurück.');
        $this->assertTrue((bool) $this->fixture->connection()->table('organisationen')->where('id', 9)->value('aktiv'), 'Vorgabewert der Datenbank.');

        $publications = $this->connect('publikationen', writable: true);
        $publication = DynamicRecord::forTable($publications)->create(['titel' => 'Neue Studie', 'jahr' => 2026, 'typ' => 'artikel']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $publication->getKey());
        $this->assertSame('Neue Studie', $this->fixture->connection()->table('publikationen')->where('id', $publication->getKey())->value('titel'));

        $publication->update(['titel' => 'Neue Studie (überarbeitet)']);
        $this->assertSame('Neue Studie (überarbeitet)', $this->fixture->connection()->table('publikationen')->where('id', $publication->getKey())->value('titel'));

        $authors = $this->connect('publikation_autoren', writable: true);
        DynamicRecord::forTable($authors)->create(['publikation_id' => $publication->getKey(), 'person_id' => 1, 'reihenfolge' => 1]);
        $this->assertSame(1, $this->fixture->connection()->table('publikation_autoren')->where('publikation_id', $publication->getKey())->count());

        $organisation->delete();
        $this->assertFalse($this->fixture->connection()->table('organisationen')->where('id', 9)->exists());
    }

    public function test_check_violation_is_classified(): void
    {
        $organisations = $this->connect('organisationen', writable: true);

        try {
            DynamicRecord::forTable($organisations)->create(['name' => 'X', 'land' => 'CH', 'typ' => 'verein']);
            $this->fail('CHECK-Regel nicht gegriffen.');
        } catch (QueryException $e) {
            $this->assertSame($this->checkViolationCode(), (int) $e->errorInfo[1]);
        }
    }

    public function test_read_only_is_enforced_by_the_database(): void
    {
        $this->connection->update(['is_read_only' => true]);
        $db = app(ConnectionRegistry::class)->connection($this->connection->fresh());

        try {
            $db->table($this->fixture->schema().'.laender')->insert(['code' => 'IT', 'name' => 'Italien']);
            $this->fail('Schreiben trotz „nur lesen“.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('READ ONLY', $e->getMessage());
        }

        $this->assertSame([], app(SchemaVerifier::class)->issues());
    }

    public function test_bulk_link_of_all_tables(): void
    {
        $bulk = app(BulkConnector::class);
        $schema = $this->connection->schemaName();
        $tables = array_keys($bulk->candidates($this->connection, $schema));

        $results = collect($bulk->connect($this->connection, $schema, $tables, ['prefix' => 'm_', 'allow_create' => true, 'menu_group' => null]))->keyBy('table');
        $finish = $bulk->finish($this->connection, $results->pluck('name')->all(), details: true);

        $this->assertCount(8, $results);
        $this->assertSame(BulkConnector::CONNECTED, $results['publikationen']['status'], 'UUID-Schlüssel ist schreibbar.');
        $this->assertSame(BulkConnector::READ_ONLY, $results['projekt_uebersicht']['status']);
        $this->assertSame(BulkConnector::READ_ONLY, $results['importprotokoll']['status'], 'Ohne Primärschlüssel nur lesbar.');
        $this->assertSame('m_personen', CustomTable::query()->where('name', 'm_projekte')->sole()->fields()->where('name', 'projektleiter_id')->sole()->optionsArray()['table']);

        // Master-Detail aus den Fremdschlüsseln.
        $details = DetailRelations::configuredFor(CustomTable::query()->where('name', 'm_personen')->sole())->map(fn ($f): string => $f->child->name.'.'.$f->column())->all();
        $this->assertSame(['m_projekte.projektleiter_id', 'm_publikation_autoren.person_id'], $details);
        $this->assertGreaterThanOrEqual(6, $finish->detailCount());
    }
}
