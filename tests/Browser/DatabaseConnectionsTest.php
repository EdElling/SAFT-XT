<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\ExternalData\ConnectionDriver;
use App\ExternalData\ConnectionTester;
use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Database\Seeders\ExternalResearchDatabase;
use Database\Seeders\MySqlResearchDatabase;
use Database\Seeders\SqlServerResearchDatabase;
use Illuminate\Database\QueryException;
use Laravel\Dusk\Browser;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\DuskTestCase;

/** Datenbankverbindungen (Bereich DBC). */
class DatabaseConnectionsTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string WRONG_PASSWORD = 'Falsch-Passwort-7d2a';

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $fixture = ExternalResearchDatabase::on(self::EXTERNAL_DATABASE);

        if (! $fixture->isCurrent()) {
            $fixture->reset();
        }
    }

    private function field(string $name): string
    {
        return '[id="form.'.$name.'"]';
    }

    /** @param  array<string, mixed>  $overrides */
    private function connection(array $overrides = []): DatabaseConnection
    {
        $own = config('database.connections.pgsql');

        return DatabaseConnection::create([
            'name' => 'Forschung',
            'driver' => ConnectionDriver::Pgsql,
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => self::EXTERNAL_DATABASE,
            'username' => $own['username'],
            'password' => $own['password'],
            'default_schema' => ExternalResearchDatabase::SCHEMA,
            ...$overrides,
        ]);
    }

    /** DBC-01 */
    public function test_connection_can_be_created_and_tested(): void
    {
        $own = config('database.connections.pgsql');

        $this->browse(function (Browser $browser) use ($own): void {
            $this->login($browser, self::ADMIN)->visit('/admin/database-connections/create')->waitFor($this->field('host'), 15);
            // Vorgaben: PostgreSQL, Port 5432, nur lesen.
            $this->assertSame('5432', $browser->value($this->field('port')));
            $this->assertTrue($browser->element($this->field('is_read_only'))->getAttribute('aria-checked') === 'true');

            $browser->type($this->field('name'), 'Forschung')
                ->type($this->field('host'), $own['host'])
                ->clear($this->field('port'))->type($this->field('port'), (string) $own['port'])
                ->type($this->field('database'), self::EXTERNAL_DATABASE)
                ->type($this->field('default_schema'), 'forschung')
                ->type($this->field('username'), $own['username'])
                ->type($this->field('new_password'), $own['password']);
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Verbindung testen', 15);

            $browser->press('Verbindung testen')->waitForText('Verbindung erfolgreich', 15)
                ->assertSee('Schema „forschung“: 6 Tabellen, 1 Views');

            $connection = DatabaseConnection::query()->where('name', 'Forschung')->sole();
            $this->assertTrue($connection->last_test_successful);
            $this->assertSame($own['password'], $connection->password);
            // Das Passwort steht nirgends auf der Seite.
            $this->assertStringNotContainsString((string) $own['password'], (string) $browser->driver->getPageSource());
        });
    }

    /** DBC-02 */
    public function test_failed_test_shows_an_understandable_message(): void
    {
        $connection = $this->connection(['password' => self::WRONG_PASSWORD]);

        $this->browse(function (Browser $browser) use ($connection): void {
            $this->login($browser, self::ADMIN)->visit("/admin/database-connections/{$connection->id}/edit")
                ->waitForText('Verbindung testen', 15)
                ->press('Verbindung testen')
                ->waitForText('Verbindungstest fehlgeschlagen', 15)
                ->assertSee('Anmeldung abgelehnt');

            $this->assertStringNotContainsString(self::WRONG_PASSWORD, (string) $browser->driver->getPageSource());
            $this->assertSame('', $browser->value($this->field('new_password')));
        });
    }

    /** DBC-03 */
    public function test_changed_settings_reset_the_test_and_list_shows_the_state(): void
    {
        $connection = $this->connection();
        app(ConnectionTester::class)->test($connection);

        $this->browse(function (Browser $browser) use ($connection): void {
            $this->login($browser, self::ADMIN)->visit('/admin/database-connections')->waitForText('Forschung', 15)
                ->assertSee(self::EXTERNAL_DATABASE);

            $browser->visit("/admin/database-connections/{$connection->id}/edit")->waitFor($this->field('default_schema'), 15)
                ->assertSee('Verbindung erfolgreich');
            $this->emptyField($browser, $this->field('default_schema'));
            $browser->type($this->field('default_schema'), 'public')
                ->press('Speichern')->waitForText('Gespeichert', 15)
                ->refresh()->waitForText('Noch nicht getestet', 15);

            $this->assertNull($connection->fresh()->last_test_successful);
        });
    }

    /** DBC-04 */
    public function test_rights_control_menu_list_and_actions(): void
    {
        $this->connection();
        $this->activeUser('leser@example.com')->givePermissionTo('database_connections.view');
        $this->activeUser('ohne@example.com');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->browse(function (Browser $reader, Browser $other): void {
            // Im Untermenü „Datenbank“ (B-066): zugeklappt nur das Untermenü, auf der Seite aufgeklappt mit dem Eintrag.
            $this->login($reader, 'leser@example.com')->assertSeeIn(self::SELECTOR_SIDEBAR, 'Datenbank');
            $reader->visit('/admin/database-connections')->waitForText('Forschung', 15)
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Datenbankverbindungen')
                ->assertDontSee('Erstellen')
                ->assertDontSee('Verbindung testen');

            $this->login($other, 'ohne@example.com')->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Datenbank');
            $other->visit('/admin/database-connections')->waitForText('Keine Berechtigung', 15);
        });
    }

    /** DBC-06 */
    public function test_bulk_link_tab_links_selected_tables(): void
    {
        $connection = $this->connection(['is_read_only' => false]);

        $this->browse(function (Browser $browser) use ($connection): void {
            $this->login($browser, self::ADMIN)->visit('/admin/database-connections/'.$connection->id.'/edit')->waitForText('Massenverknüpfung', 15);

            // Ohne erfolgreichen Test nur der Hinweis.
            $browser->clickAtXPath("//button[contains(@class, 'fi-tabs-item')][contains(., 'Massenverknüpfung')]")
                ->waitForText('sobald der Verbindungstest erfolgreich war', 15);

            $this->clickVisibleButton($browser, 'Verbindung testen')->waitForText('Verbindung erfolgreich', 30);
            $browser->visit('/admin/database-connections/'.$connection->id.'/edit')
                ->clickAtXPath("//button[contains(@class, 'fi-tabs-item')][contains(., 'Massenverknüpfung')]")
                ->waitForText('organisationen', 20)
                ->assertSee('projekt_uebersicht');

            foreach (['organisationen', 'personen'] as $table) {
                $browser->clickAtXPath("//tr[.//td[contains(., '{$table}')]][not(.//td[contains(., 'publikation')])]//input[@type='checkbox']");
            }

            $this->clickVisibleButton($browser, 'Ausgewählte anbinden …')->waitForText('Präfix für die technischen Namen', 15)
                ->assertSee('Master-Detail aus Fremdschlüsseln einrichten')
                ->type('input[id$="prefix"]', 'b_');
            $this->clickVisibleButton($browser, 'Anbinden')
                ->waitForText('Massenverknüpfung abgeschlossen', 60)
                ->assertSee('Eine Detailtabelle eingerichtet.')
                ->assertSee('Ergebnis der Massenverknüpfung')
                ->assertSee('b_personen');
        });

        $people = CustomTable::query()->where('name', 'b_personen')->with('fields')->sole();
        $employer = $people->fields->firstWhere('name', 'arbeitgeber_id');
        $this->assertSame('b_organisationen', $employer->optionsArray()['table'] ?? null);
        // Unter einer Organisation erscheinen ihre Personen.
        $this->assertSame([(int) $employer->id], array_map('intval', (array) CustomTable::query()->where('name', 'b_organisationen')->sole()->detail_field_ids));
    }

    /** DBC-08 */
    public function test_connection_is_deleted_together_with_its_linked_tables(): void
    {
        $connection = $this->connection();
        $service = app(TableConnectorService::class);
        $service->connect($connection, ExternalResearchDatabase::SCHEMA, 'organisationen', ['name' => 'del_orgs']);
        $service->connect($connection, ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'del_personen']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->browse(function (Browser $browser) use ($connection): void {
            $this->login($browser, self::ADMIN)->visit('/admin/database-connections/'.$connection->id.'/edit')->waitForText('Verbindung testen', 15);

            $this->clickVisibleButton($browser, 'Löschen')
                ->waitForText('Über diese Verbindung sind 2 Tabellen angebunden', 15)
                ->assertPresent('[data-affected-table="del_orgs"]')
                ->assertPresent('[data-affected-table="del_personen"]')
                ->assertSee('forschung.personen')
                ->clickAtXPath("//label[contains(., 'Anbindungen mitlöschen')]")
                ->waitFor('input[id$="confirmation"]', 15)
                ->type('input[id$="confirmation"]', 'Forschung');
            $this->clickVisibleButton($browser, 'Verbindung löschen')
                ->waitForText('Verbindung „Forschung“ und 2 Anbindungen gelöscht.', 20)
                ->waitForLocation('/admin/database-connections', 15);
        });

        $this->assertModelMissing($connection);
        $this->assertFalse(CustomTable::query()->whereIn('name', ['del_orgs', 'del_personen'])->exists());
    }

    /** DBC-09 */
    public function test_sql_server_connection_is_created_tested_and_linked(): void
    {
        try {
            $fixture = SqlServerResearchDatabase::on((string) env('EXT_MSSQL_DB_DATABASE', 'SAFT2_ext_dusk'));

            if (! $fixture->isCurrent()) {
                $fixture->reset();
            }
        } catch (QueryException|RuntimeException $e) {
            $this->markTestSkipped('Kein SQL Server erreichbar (MSSQL_*, EXT_MSSQL_DB_DATABASE) oder pdo_sqlsrv fehlt: '.($e->getPrevious()?->getMessage() ?? $e->getMessage()));
        }

        $own = config('database.connections.sqlsrv');

        $this->browse(function (Browser $browser) use ($own, $fixture): void {
            $this->login($browser, self::ADMIN)->visit('/admin/database-connections/create')->waitFor($this->field('host'), 15);
            $browser->clickAtXPath("//label[normalize-space(.)='Microsoft SQL Server']")
                ->waitUsing(10, 100, fn (): bool => $browser->value($this->field('port')) === '1433')
                ->assertSee('SQL Server kann „nur lesen“ nicht je Verbindung durchsetzen');

            $browser->type($this->field('name'), 'Forschung SQL Server')
                ->type($this->field('host'), $own['host'])
                ->type($this->field('database'), $fixture->database())
                ->type($this->field('default_schema'), SqlServerResearchDatabase::SCHEMA)
                ->type($this->field('username'), $own['username'])
                ->type($this->field('new_password'), $own['password']);
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Verbindung testen', 15);

            $browser->press('Verbindung testen')->waitForText('Verbindung erfolgreich', 30)
                ->assertSee('Schema „forschung“: 7 Tabellen, 1 Views');

            // Massenverknüpfung: Personen anbinden (Verbindung nur lesen) und Daten ansehen.
            $browser->refresh()->waitForText('Massenverknüpfung', 15)
                ->clickAtXPath("//button[contains(@class, 'fi-tabs-item')][contains(., 'Massenverknüpfung')]")
                ->waitForText('personen', 20)
                ->clickAtXPath("//tr[.//td[normalize-space(.)='personen']]//input[@type='checkbox']");
            $this->clickVisibleButton($browser, 'Ausgewählte anbinden …')->waitForText('Präfix für die technischen Namen', 15)
                ->type('input[id$="prefix"]', 'ms_');
            $this->clickVisibleButton($browser, 'Anbinden')->waitForText('Massenverknüpfung abgeschlossen', 60);

            // Vorgabe 10 Zeilen je Seite; Person 17 erscheint nach Umstellen auf 25.
            $browser->visit('/admin/data/ms_personen')->waitForText('Anna Keller', 20)
                ->select('.fi-pagination-records-per-page-select:not(.fi-compact) select', '25')
                ->waitForText('Rahel Bühler', 20)->assertDontSee('Unerwarteter Fehler');
        });

        $this->assertSame(ConnectionDriver::Sqlsrv, DatabaseConnection::query()->where('name', 'Forschung SQL Server')->sole()->driver);
    }

    /** DBC-10 */
    public function test_mariadb_connection_is_created_tested_and_linked(): void
    {
        try {
            $fixture = MySqlResearchDatabase::on((string) env('EXT_MARIADB_DB_DATABASE', 'SAFT2_ext_dusk'), 'mariadb');

            if (! $fixture->isCurrent()) {
                $fixture->reset();
            }
        } catch (QueryException|RuntimeException $e) {
            $this->markTestSkipped('Kein MariaDB-Server erreichbar (MARIADB_*, EXT_MARIADB_DB_DATABASE): '.($e->getPrevious()?->getMessage() ?? $e->getMessage()));
        }

        $own = config('database.connections.mariadb');

        $this->browse(function (Browser $browser) use ($own, $fixture): void {
            $this->login($browser, self::ADMIN)->visit('/admin/database-connections/create')->waitFor($this->field('host'), 15);
            $browser->clickAtXPath("//label[normalize-space(.)='MariaDB']")
                ->waitUsing(10, 100, fn (): bool => $browser->value($this->field('port')) === '3306')
                // MariaDB setzt „nur lesen“ selbst durch: der allgemeine Hinweis, nicht der für SQL Server.
                ->assertSee('Die Datenbank lehnt jede Änderung über diese Verbindung ab')
                ->assertDontSee('SQL Server kann „nur lesen“');

            $browser->type($this->field('name'), 'Forschung MariaDB')
                ->type($this->field('host'), $own['host'])
                ->clear($this->field('port'))->type($this->field('port'), (string) $own['port'])
                ->type($this->field('database'), $fixture->schema())
                ->type($this->field('username'), $own['username'])
                ->type($this->field('new_password'), $own['password']);
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Verbindung testen', 15);

            $browser->press('Verbindung testen')->waitForText('Verbindung erfolgreich', 30)
                ->assertSee('MariaDB')
                ->assertSee('7 Tabellen, 1 Views');

            $browser->refresh()->waitForText('Massenverknüpfung', 15)
                ->clickAtXPath("//button[contains(@class, 'fi-tabs-item')][contains(., 'Massenverknüpfung')]")
                ->waitForText('personen', 20)
                ->clickAtXPath("//tr[.//td[normalize-space(.)='personen']]//input[@type='checkbox']");
            $this->clickVisibleButton($browser, 'Ausgewählte anbinden …')->waitForText('Präfix für die technischen Namen', 15)
                ->type('input[id$="prefix"]', 'mdb_');
            $this->clickVisibleButton($browser, 'Anbinden')->waitForText('Massenverknüpfung abgeschlossen', 60);

            // Vorgabe 10 Zeilen je Seite; Person 17 erscheint nach Umstellen auf 25.
            $browser->visit('/admin/data/mdb_personen')->waitForText('Anna Keller', 20)
                ->select('.fi-pagination-records-per-page-select:not(.fi-compact) select', '25')
                ->waitForText('Rahel Bühler', 20)->assertDontSee('Unerwarteter Fehler');
        });

        $this->assertSame(ConnectionDriver::Mariadb, DatabaseConnection::query()->where('name', 'Forschung MariaDB')->sole()->driver);
    }
}
