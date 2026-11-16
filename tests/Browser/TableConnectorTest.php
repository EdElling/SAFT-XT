<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataPermissions;
use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Database\Seeders\ExternalResearchDatabase;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\DuskTestCase;

/** Table-Connector, nur lesen (Bereich TCO). */
class TableConnectorTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    private const string ADMIN = 'admin@example.com';

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $this->prepareExternalDatabase();
        $this->connection = $this->researchConnection();
    }

    protected function externalDatabaseName(): string
    {
        return self::EXTERNAL_DATABASE;
    }

    private function connect(string $table, string $schema = ExternalResearchDatabase::SCHEMA, ?string $name = null): CustomTable
    {
        return app(TableConnectorService::class)->connect($this->connection, $schema, $table, ['name' => $name ?? $table]);
    }

    /** TCO-01 */
    public function test_table_can_be_connected_and_columns_adjusted(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-connector/create')->waitFor('[id="form.database_connection_id"]', 15);
            $browser->select('[id="form.database_connection_id"]', (string) $this->connection->id)->pause(1200);
            $this->assertSame('forschung', $browser->value('[id="form.external_schema"]'));
            $this->pickOption($browser, 'form.external_table', 'projekte');
            $browser->waitForText('10 Spalten werden übernommen. Schlüssel: id.', 15);
            $this->assertSame('forschung_projekte', $browser->value('[id="form.name"]'));
            $this->assertSame('Projekte', $browser->value('[id="form.label.de"]'));

            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Spalten', 15);
            $this->scrollThroughPage($browser);

            // Bezeichnung einer Spalte ändern und das Budget ausblenden.
            $browser->waitFor('tr.fi-ta-row', 15);
            $this->rowAction($browser, 'projektleiter_id', 'Bearbeiten');
            $browser->waitFor('input[id="mountedActionSchema0.label.de"]', 15);
            $this->emptyField($browser, 'input[id="mountedActionSchema0.label.de"]');
            $browser->type('input[id="mountedActionSchema0.label.de"]', 'Projektleitung');
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->waitUntilMissing('input[id="mountedActionSchema0.label.de"]', 15)->waitForText('Projektleitung', 15);
            $browser->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'budget')]//td[contains(@class, 'fi-ta-cell-show-in-table')]//*[@role='switch']")->pause(1000);

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)
                ->clickAtXPath("//a[contains(@class, 'fi-sidebar-item-btn')][normalize-space(.)='Projekte']")
                ->waitForText('Gletscherschmelze und Wasserhaushalt', 15)
                ->assertSee('Forschungsprojekte mit Leitung und Partner')
                ->assertSee('Projektleitung')
                ->assertMissing('th.fi-ta-header-cell-budget')
                ->assertDontSee('Neuer Datensatz');
        });
    }

    /** TCO-02 */
    public function test_details_and_selection_work_with_uuid_keys(): void
    {
        $this->connect('publikationen');

        $this->browse(function (Browser $browser): void {
            // Sortiert nach UUID: alle 25 Publikationen auf einer Seite.
            $this->login($browser, self::ADMIN)->visit('/admin/data/publikationen')->waitForText('Studie', 15);
            $this->showRowsPerPage($browser)->waitForText('Studie 1:', 15)
                ->assertMissing('th.fi-ta-header-cell-id');

            $this->openDetails($browser, 'Studie 1: Gletscherschmelze');
            $browser->assertSee('10.5555/saft.1000')
                ->assertDontSee('Bearbeiten')
                ->assertDontSee('Verlauf');
            $this->clickVisibleButton($browser, 'Schliessen');
            $browser->pause(500);

            $this->clickRow($browser, 'Studie 1: Gletscherschmelze');
            $browser->waitFor('tr.saft-row-selected', 15)->waitForText('Publikationen: Studie 1', 15);
            $this->assertStringContainsString('sel=s.', (string) $browser->driver->getCurrentURL());

            // Neu laden zeigt dieselbe Auswahl.
            $browser->refresh()->waitFor('tr.saft-row-selected', 15)->assertSee('Publikationen: Studie 1');
        });
    }

    /** TCO-03 */
    public function test_views_and_tables_without_key_are_list_only(): void
    {
        $this->connect('projekt_uebersicht');
        $this->connect('importprotokoll', 'public');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/projekt_uebersicht')->waitForText('Gletscherschmelze und Wasserhaushalt', 15)
                ->assertDontSee('Details');
            $this->clickRow($browser, 'Gletscherschmelze und Wasserhaushalt');
            $browser->pause(800)->assertMissing('tr.saft-row-selected');

            $browser->visit('/admin/data/importprotokoll')->waitForText('ORCID-Abgleich', 15)
                ->assertSee('3 Zeilen übersprungen (DOI doppelt)')
                ->assertDontSee('Details');
        });
    }

    /** TCO-04 */
    public function test_rights_for_connector_and_connected_data(): void
    {
        $this->connect('personen');
        $this->activeUser('leser@example.com')->givePermissionTo(DataPermissions::name('personen', 'view'));
        $this->activeUser('connector@example.com')->givePermissionTo('table_connector.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->browse(function (Browser $reader, Browser $connector): void {
            $this->login($reader, 'leser@example.com')
                ->assertSeeIn(self::SELECTOR_SIDEBAR, 'Personen')
                ->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Table-Connector');
            $reader->visit('/admin/data/personen')->waitForText('Keller', 15)->assertDontSee('Exportieren');
            $reader->visit('/admin/table-connector')->waitForText('Keine Berechtigung', 15);

            $this->login($connector, 'connector@example.com')->assertSeeIn(self::SELECTOR_SIDEBAR, 'Table-Connector');
            $connector->visit('/admin/table-connector')->waitForText('forschung.personen', 15)
                ->assertDontSee('Tabelle anbinden');
        });
    }

    /** TCO-05 */
    public function test_unavailable_database_shows_a_message(): void
    {
        $this->connect('personen');
        $this->connection->update(['port' => 1]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/personen')
                ->waitForText('Daten nicht verfügbar', 15)
                ->assertSee('zurzeit nicht erreichbar')
                ->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** TCO-07 */
    public function test_relation_is_suggested_and_display_field_can_be_chosen(): void
    {
        $projects = $this->connect('projekte');
        $this->connect('personen');

        $this->browse(function (Browser $browser) use ($projects): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$projects->id}/edit");
            $this->scrollThroughPage($browser);
            $browser->waitFor('tr.fi-ta-row', 15)->assertSee('→ personen.vorname ·');

            $this->rowAction($browser, 'projektleiter_id', 'Bearbeiten');
            $browser->waitFor('select[id="mountedActionSchema0.relation_table"]', 15);
            $this->assertSame('personen', $browser->value('select[id="mountedActionSchema0.relation_table"]'));
            $browser->assertSee('Vorname (vorname)');
            // Zweites Anzeigefeld: „Vorname Nachname“.
            $this->pickOption($browser, 'mountedActionSchema0.relation_display', 'Nachname');
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->waitUntilMissing('select[id="mountedActionSchema0.relation_table"]', 15)->waitForText('→ personen.vorname + nachname', 15);

            $browser->visit('/admin/data/projekte')->waitForText('Gletscherschmelze und Wasserhaushalt', 15);
            $row = $browser->driver->findElement(WebDriverBy::xpath("//tr[contains(@class, 'fi-ta-row')][contains(., 'P-01')]"));
            $this->assertStringContainsString('Anna Keller', $row->getText());
        });
    }

    /** TCO-08 */
    public function test_related_value_opens_preview_and_switches_to_the_record(): void
    {
        $this->connect('projekte');
        $this->connect('personen');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/projekte')->waitForText('Gletscherschmelze und Wasserhaushalt', 15)
                ->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'P-01')]//td[contains(@class, 'projektleiter-id')]//*[self::a or self::button]")
                ->waitForText('Personen: Anna', 15)
                ->assertSee('anna.keller@example.org');

            $browser->clickAtXPath("//*[self::a or self::button][contains(., 'Zu „Projektleiter“ wechseln')]")
                ->waitFor('tr.saft-row-selected', 15)
                ->assertPathIs('/admin/data/personen')
                ->assertSeeIn('tr.saft-row-selected', 'Keller');
        });
    }

    /** TCO-09 */
    public function test_master_detail_works_across_uuid_keys(): void
    {
        $projects = $this->connect('projekte');
        $publications = $this->connect('publikationen');
        $this->connect('publikation_autoren');
        app(TableConnectorService::class)->update($publications->fresh(), ['detail_field_ids' => [
            (int) CustomTable::query()->where('name', 'publikation_autoren')->sole()->fields()->where('name', 'publikation_id')->value('id'),
        ]]);

        $this->browse(function (Browser $browser) use ($projects): void {
            // Details der Projekte in der Oberfläche einstellen.
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$projects->id}/edit")
                ->waitForText('Details (Master-Detail)', 15)
                ->clickAtXPath("//label[contains(., 'Publikationen (über „Projekt“)')]//input")
                ->press('Speichern')->waitForText('Gespeichert', 15);

            $browser->visit('/admin/data/projekte')->waitForText('Gletscherschmelze und Wasserhaushalt', 15);
            $this->clickRow($browser, 'Gletscherschmelze und Wasserhaushalt');
            $browser->waitForText('Publikationen (über „Projekt“)', 15);
            $this->clickRow($browser, 'Studie 1: Gletscherschmelze');
            $browser->waitForText('Publikation autoren (über „Publikation“)', 15);
            $this->assertCount(2, $this->selectedRows($browser));
            $this->assertStringContainsString(':s.', urldecode((string) $browser->driver->getCurrentURL()));

            // Neu laden zeigt dieselben Ebenen.
            $browser->refresh()->waitForText('Publikation autoren (über „Publikation“)', 15);
            $this->assertCount(2, $this->selectedRows($browser));
        });
    }

    /** TCO-15 */
    public function test_related_value_opens_preview_in_table_without_single_key(): void
    {
        $this->connect('publikationen');
        $this->connect('personen');
        $this->connect('publikation_autoren');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/publikation_autoren')->waitFor('tr.fi-ta-row', 15)
                ->clickAtXPath("(//tr[contains(@class, 'fi-ta-row')]//td[contains(@class, 'person-id')]//*[self::a or self::button])[1]")
                ->waitForText('Personen:', 15)
                ->assertDontSee('Datenbankfehler');
        });
    }
}
