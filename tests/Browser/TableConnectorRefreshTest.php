<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Dusk\Browser;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\DuskTestCase;

/** Table-Connector, Abgleich mit dem Schema (Bereich TCO). Ändert die externe Testdatenbank. */
class TableConnectorRefreshTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    private const string ADMIN = 'admin@example.com';

    private DatabaseConnection $connection;

    private ExternalResearchDatabase $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $this->fixture = ExternalResearchDatabase::on(self::EXTERNAL_DATABASE);
        $this->fixture->reset();
        $this->connection = $this->researchConnection();
    }

    protected function tearDown(): void
    {
        // Die übrigen Browser-Tests erwarten den ursprünglichen Aufbau.
        $this->fixture->reset();
        parent::tearDown();
    }

    protected function externalDatabaseName(): string
    {
        return self::EXTERNAL_DATABASE;
    }

    private function connect(string $table): CustomTable
    {
        return app(TableConnectorService::class)->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);
    }

    private function alter(string $table, \Closure $change): void
    {
        $this->fixture->connection()->getSchemaBuilder()->table(ExternalResearchDatabase::SCHEMA.'.'.$table, $change);
    }

    /** TCO-17 */
    public function test_new_column_is_reported_and_taken_over_marked_as_new(): void
    {
        $organisations = $this->connect('organisationen');
        $this->alter('organisationen', fn (Blueprint $t) => $t->string('kuerzel', 20)->nullable());

        $this->browse(function (Browser $browser) use ($organisations): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-connector')
                ->waitForText('1 Abweichung', 15);

            $browser->visit("/admin/table-connector/{$organisations->id}/edit")
                ->waitForText('Die externe Tabelle hat sich geändert', 15)
                ->assertSee('Neue Spalte „kuerzel“');

            $this->clickVisibleButton($browser, 'Neu einlesen')->waitForText('Schema neu einlesen', 15);
            $this->clickVisibleButton($browser, 'Übernehmen')->waitForText('Schema übernommen', 15)
                ->waitUntilMissingText('Die externe Tabelle hat sich geändert', 15);
            $this->scrollThroughPage($browser);
            $browser->assertSee('kuerzel')->assertSee('neu');

            $browser->visit('/admin/table-connector')->waitForText('aktuell', 15);
            $browser->visit('/admin/data/organisationen')->waitForText('ETH Zürich', 15)->assertSee('Kuerzel');
        });
    }

    /** TCO-18 */
    public function test_missing_column_is_hidden_marked_and_can_be_removed(): void
    {
        $organisations = $this->connect('organisationen');
        $this->alter('organisationen', fn (Blueprint $t) => $t->dropColumn('webseite'));

        $this->browse(function (Browser $browser) use ($organisations): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$organisations->id}/edit")
                ->waitForText('Spalte „webseite“ fehlt', 15);

            $this->clickVisibleButton($browser, 'Neu einlesen')->waitForText('Schema neu einlesen', 15);
            $this->clickVisibleButton($browser, 'Übernehmen')->waitForText('Schema übernommen', 15);
            $this->scrollThroughPage($browser);
            $browser->waitForText('fehlt', 15);

            $browser->visit('/admin/data/organisationen')->waitForText('ETH Zürich', 15)->assertDontSee('Webseite');

            $browser->visit("/admin/table-connector/{$organisations->id}/edit")->waitForText('Spalten', 15);
            $this->scrollThroughPage($browser);
            $this->clickVisibleButton($browser, 'Endgültig entfernen')->waitForText('Die externe Datenbank bleibt unverändert', 15);
            $this->clickVisibleButton($browser, 'Bestätigen')->pause(1500);

            $this->assertNull($organisations->fresh('fields')->fields->firstWhere('name', 'webseite'));
        });
    }

    /** TCO-19 */
    public function test_data_page_explains_a_changed_table_instead_of_failing(): void
    {
        $this->connect('organisationen');
        $this->alter('organisationen', fn (Blueprint $t) => $t->dropColumn('webseite'));

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/organisationen')
                ->waitForText('hat sich geändert', 15)
                ->assertSee('Bitte im Table-Connector neu einlesen')
                ->assertDontSee('SQLSTATE');
        });
    }
}
