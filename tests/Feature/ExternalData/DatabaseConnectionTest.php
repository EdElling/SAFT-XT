<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\ExternalData\ConnectionDriver;
use App\ExternalData\ConnectionRegistry;
use App\ExternalData\ConnectionTester;
use App\Filament\Resources\DatabaseConnections\Pages\CreateDatabaseConnection;
use App\Filament\Resources\DatabaseConnections\Pages\EditDatabaseConnection;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Menu;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Datenbankverbindungen (Etappe 1 des Table-Connectors). */
class DatabaseConnectionTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private const string PASSWORD_PROBE = 'Falsch-9f3c1e!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();

        $fixture = ExternalResearchDatabase::on((string) env('EXT_DB_DATABASE'));

        if (! $fixture->isCurrent()) {
            $fixture->reset();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function connection(array $overrides = []): DatabaseConnection
    {
        $own = config('database.connections.pgsql');

        return DatabaseConnection::create([
            'name' => 'Forschung',
            'driver' => ConnectionDriver::Pgsql,
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => (string) env('EXT_DB_DATABASE'),
            'username' => $own['username'],
            'password' => $own['password'],
            'default_schema' => ExternalResearchDatabase::SCHEMA,
            ...$overrides,
        ]);
    }

    public function test_password_is_encrypted_hidden_and_never_logged(): void
    {
        $connection = $this->connection(['password' => self::PASSWORD_PROBE]);

        $raw = (string) DB::table('database_connections')->where('id', $connection->id)->value('password');
        $this->assertNotSame(self::PASSWORD_PROBE, $raw);
        $this->assertSame(self::PASSWORD_PROBE, $connection->fresh()->password);
        $this->assertArrayNotHasKey('password', $connection->toArray());

        $connection->update(['password' => self::PASSWORD_PROBE.'2']);
        $log = Activity::query()->where('subject_type', $connection->getMorphClass())->get()->toJson();
        $this->assertStringNotContainsString(self::PASSWORD_PROBE, $log);
        $this->assertStringContainsString('password', $log);
    }

    public function test_successful_test_reports_version_and_schemas(): void
    {
        $connection = $this->connection();
        $this->assertSame('prefer', $connection->ssl_mode);

        $result = app(ConnectionTester::class)->test($connection);

        $this->assertTrue($result->successful, $result->message);
        $this->assertContains('forschung', $result->schemas);
        $this->assertContains('public', $result->schemas);
        $this->assertNotContains('pg_catalog', $result->schemas);
        $this->assertNotContains('information_schema', $result->schemas);
        $this->assertStringContainsString('6 Tabellen', $result->message);
        $this->assertStringContainsString('1', $result->message); // 1 View

        $connection->refresh();
        $this->assertTrue($connection->last_test_successful);
        $this->assertNotNull($connection->last_tested_at);
        $this->assertContains('forschung', $connection->known_schemas);
    }

    public function test_failures_have_understandable_messages_without_password(): void
    {
        $cases = [
            'auth' => ['password' => self::PASSWORD_PROBE],
            'unknown_database' => ['database' => 'SAFT2_gibt_es_nicht'],
            'unknown_schema' => ['default_schema' => 'archiv'],
            'unreachable' => ['host' => '127.0.0.1', 'port' => 1],
        ];

        foreach ($cases as $expected => $overrides) {
            $connection = $this->connection(['name' => $expected, ...$overrides]);
            $result = app(ConnectionTester::class)->test($connection);

            $this->assertFalse($result->successful, $expected);
            $prefix = explode(':', __("db_connections.errors.{$expected}", ['schema' => 'archiv', 'database' => 'SAFT2_gibt_es_nicht', 'host' => '127.0.0.1', 'port' => 1, 'seconds' => 5]))[0];
            $this->assertStringStartsWith($prefix, $result->message, $expected);
            $this->assertStringNotContainsString(self::PASSWORD_PROBE, $result->message);
            $this->assertFalse($connection->fresh()->last_test_successful);
        }
    }

    public function test_unreachable_host_fails_within_the_connect_timeout(): void
    {
        // Nicht routbare Adresse: Ohne Zeitlimit würde der Aufbau sehr lange hängen.
        $connection = $this->connection(['host' => '10.255.255.1', 'connect_timeout' => 1]);

        $started = microtime(true);
        $result = app(ConnectionTester::class)->test($connection);

        $this->assertFalse($result->successful);
        $this->assertLessThan(4, microtime(true) - $started);
    }

    public function test_registry_is_isolated_and_rejects_unsafe_values(): void
    {
        $before = [config('database.connections.pgsql'), config('database.connections.app_data')];
        $connection = $this->connection();

        $this->assertSame((string) env('EXT_DB_DATABASE'), app(ConnectionRegistry::class)->connection($connection)->getDatabaseName());
        $this->assertSame($before, [config('database.connections.pgsql'), config('database.connections.app_data')]);
        $this->assertSame('SAFT2_test', DB::connection()->getDatabaseName());

        foreach ([['host' => 'localhost;sslmode=disable'], ['database' => "x' host=evil"], ['default_schema' => 'a b']] as $unsafe) {
            try {
                app(ConnectionRegistry::class)->config(new DatabaseConnection([...$connection->getAttributes(), ...$unsafe, 'driver' => 'pgsql'])->forceFill(['id' => $connection->id]));
                $this->fail('Unsicherer Wert akzeptiert: '.json_encode($unsafe));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        app(ConnectionRegistry::class)->config(new DatabaseConnection(['driver' => 'pgsql']));
    }

    public function test_read_only_and_statement_timeout_are_enforced_by_the_database(): void
    {
        $readOnly = app(ConnectionRegistry::class)->connection($this->connection(['statement_timeout' => 1]));

        try {
            $readOnly->table('forschung.organisationen')->insert(['name' => 'Nur-lesen-Test', 'land' => 'CH', 'typ' => 'behoerde']);
            $this->fail('Schreiben trotz „nur lesen“ möglich.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $readOnly->select('select pg_sleep(2)');
    }

    public function test_writable_connection_can_write(): void
    {
        $writable = app(ConnectionRegistry::class)->connection($this->connection(['is_read_only' => false]));

        $writable->beginTransaction();
        $writable->table('forschung.organisationen')->insert(['name' => 'Schreibtest', 'land' => 'CH', 'typ' => 'behoerde']);
        $this->assertSame(1, $writable->table('forschung.organisationen')->where('name', 'Schreibtest')->count());
        $writable->rollBack();
    }

    public function test_changed_connection_settings_invalidate_the_last_test(): void
    {
        $connection = $this->connection();
        app(ConnectionTester::class)->test($connection);
        $this->assertTrue($connection->fresh()->last_test_successful);

        $connection->update(['is_active' => false]);
        $this->assertTrue($connection->fresh()->last_test_successful);

        $connection->update(['default_schema' => 'public']);
        $this->assertNull($connection->fresh()->last_test_successful);
    }

    public function test_form_creates_connection_keeps_password_and_validates_input(): void
    {
        $this->actingAs($this->superAdmin());
        $own = config('database.connections.pgsql');

        Livewire::test(CreateDatabaseConnection::class)
            ->assertFormSet(['driver' => 'pgsql', 'port' => 5432, 'is_read_only' => true, 'ssl_mode' => 'prefer'])
            ->fillForm([
                'name' => 'Forschung',
                'host' => $own['host'],
                'port' => (int) $own['port'],
                'database' => (string) env('EXT_DB_DATABASE'),
                'default_schema' => 'forschung',
                'username' => $own['username'],
                'new_password' => $own['password'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $connection = DatabaseConnection::query()->where('name', 'Forschung')->firstOrFail();
        $this->assertSame($own['password'], $connection->password);

        // Leeres Passwortfeld behält das gespeicherte.
        Livewire::test(EditDatabaseConnection::class, ['record' => $connection->getRouteKey()])
            ->assertFormSet(['new_password' => null])
            ->fillForm(['name' => 'Forschung neu'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($own['password'], $connection->fresh()->password);

        Livewire::test(EditDatabaseConnection::class, ['record' => $connection->getRouteKey()])
            ->fillForm(['host' => 'db.example.com;sslmode=disable', 'database' => "x' host=evil", 'port' => 70000])
            ->call('save')
            ->assertHasFormErrors(['host' => 'regex', 'database' => 'regex', 'port']);
    }

    public function test_test_action_saves_and_tests_the_shown_configuration(): void
    {
        $this->actingAs($this->superAdmin());
        $connection = $this->connection(['default_schema' => 'archiv']);

        Livewire::test(EditDatabaseConnection::class, ['record' => $connection->getRouteKey()])
            ->fillForm(['default_schema' => 'forschung'])
            ->callAction('testConnection')
            ->assertNotified(__('db_connections.test.success'));

        $this->assertSame('forschung', $connection->fresh()->default_schema);
        $this->assertTrue($connection->fresh()->last_test_successful);
    }

    public function test_permissions_and_menu_entry(): void
    {
        $this->connection();

        $this->actingAs($this->userWithPermissions(['users.view']))->get('/admin/database-connections')->assertForbidden();
        $this->actingAs($this->userWithPermissions(['database_connections.view']))->get('/admin/database-connections')->assertOk()->assertSee('Forschung');

        $entry = Menu::query()->where('route', 'filament.admin.resources.database-connections.index')->firstOrFail();
        $this->assertSame('database_connections.view', $entry->permission_name);
        $this->assertSame('Database connections', $entry->getTranslation('label', 'en'));
    }

    public function test_demo_command_explains_unreachable_postgresql(): void
    {
        config(['database.connections.pgsql.port' => 1]);

        $this->artisan('external-demo:install', ['--database' => 'nirgends_ext'])
            ->expectsOutputToContain('Zugangsdaten PGSQL_*')
            ->assertFailed();

        $this->assertSame(0, DatabaseConnection::query()->count());
    }

    public function test_demo_command_refuses_foreign_databases_and_creates_the_connection_once(): void
    {
        $this->artisan('external-demo:install', ['--database' => 'SAFT2'])->assertFailed();

        $this->artisan('external-demo:install', ['--database' => (string) env('EXT_DB_DATABASE'), '--name' => 'Demo'])->assertSuccessful();
        $this->artisan('external-demo:install', ['--database' => (string) env('EXT_DB_DATABASE'), '--name' => 'Demo'])->assertSuccessful();

        $demo = DatabaseConnection::query()->where('name', 'Demo')->sole();
        $this->assertTrue($demo->is_read_only);
        $this->assertTrue(app(ConnectionTester::class)->test($demo)->successful);
    }

    public function test_fixture_refuses_to_reset_other_databases(): void
    {
        $this->expectException(RuntimeException::class);
        ExternalResearchDatabase::on('SAFT2_test');
    }
}
