<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Database\Seeders\ExternalResearchDatabase;
use Laravel\Dusk\Browser;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\DuskTestCase;

/** Table-Connector, Schreiben (Bereich TCO). Baut die externe Testdatenbank vor jedem Test neu auf. */
class TableConnectorWriteTest extends DuskTestCase
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
        $this->connection = $this->researchConnection(['is_read_only' => false]);
    }

    protected function externalDatabaseName(): string
    {
        return self::EXTERNAL_DATABASE;
    }

    private function connect(string $table, bool $writable = true): CustomTable
    {
        $connected = app(TableConnectorService::class)->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);

        return $writable
            ? app(TableConnectorService::class)->update($connected, ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true])
            : $connected;
    }

    /** TCO-10 */
    public function test_writing_is_enabled_per_table_only_if_possible(): void
    {
        $organisations = $this->connect('organisationen', writable: false);
        $this->connection->update(['is_read_only' => true]);

        $this->browse(function (Browser $browser) use ($organisations): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$organisations->id}/edit")
                ->waitForText('Schreiben', 15)
                ->assertSee('Die Datenbankverbindung steht auf „nur lesen“');
            $this->assertNotNull($browser->element('[id="form.allow_create"]')->getAttribute('disabled'));

            $this->connection->update(['is_read_only' => false]);
            $browser->refresh()->waitForText('Schreiben', 15)
                ->click('[id="form.allow_create"]')
                ->click('[id="form.allow_update"]')
                ->press('Speichern')->waitForText('Gespeichert', 15);

            $this->assertTrue($organisations->fresh()->allow_create);
            $this->assertFalse($organisations->fresh()->allow_delete);

            $browser->visit('/admin/data/organisationen')->waitForText('ETH Zürich', 15)->assertSee('Neuer Datensatz');
            $this->openDetails($browser, 'ETH Zürich');
            $browser->assertSee('Bearbeiten')->assertDontSee('Löschen');
        });
    }

    /** TCO-11 */
    public function test_new_record_gets_its_key_from_the_database(): void
    {
        $this->connect('organisationen');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/organisationen')->waitForText('ETH Zürich', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="mountedActionSchema0.name"]', 15)
                ->assertMissing('input[id="mountedActionSchema0.id"]')
                ->type('input[id="mountedActionSchema0.name"]', 'Hochschule Luzern')
                ->type('input[id="mountedActionSchema0.land"]', 'CH')
                ->select('select[id="mountedActionSchema0.typ"]', 'hochschule');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Hochschule Luzern', 15);

            $this->assertSame(9, (int) $this->fixture->connection()->table('forschung.organisationen')->where('name', 'Hochschule Luzern')->value('id'));

            // Die CHECK-Regel der Datenbank (Typ) ist eine Werteliste: „verein“ wird gar nicht angeboten.
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('select[id="mountedActionSchema0.typ"]', 15);
            $options = $browser->script("return [...document.querySelectorAll('select[id=\"mountedActionSchema0.typ\"] option')].map(o => o.value).filter(v => v !== '');")[0];
            $this->assertSame(['hochschule', 'forschungsinstitut', 'unternehmen', 'behoerde'], $options);
        });
    }

    /** TCO-12 */
    public function test_edit_with_relation_and_fixed_natural_key(): void
    {
        $this->connect('personen', writable: false);
        $this->connect('projekte');
        $this->connect('laender');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/projekte')->waitForText('Gletscherschmelze und Wasserhaushalt', 15);
            $this->openDetails($browser, 'Gletscherschmelze und Wasserhaushalt');
            $this->clickVisibleButton($browser, 'Bearbeiten');
            $browser->waitFor('input[id="mountedActionSchema1.titel"]', 15);
            $this->emptyField($browser, 'input[id="mountedActionSchema1.titel"]');
            $browser->type('input[id="mountedActionSchema1.titel"]', 'Gletscher und Wasser');
            $this->pickOption($browser, 'mountedActionSchema1.projektleiter_id', 'Bruno');
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->waitForText('Gletscher und Wasser', 15);

            $row = $this->fixture->connection()->table('forschung.projekte')->where('kurzname', 'P-01')->first();
            $this->assertSame('Gletscher und Wasser', $row->titel);
            $this->assertSame(2, (int) $row->projektleiter_id);

            // Natürlicher Schlüssel: beim Bearbeiten gesperrt.
            $browser->visit('/admin/data/laender')->waitForText('Schweiz', 15);
            $this->openDetails($browser, 'Schweiz');
            $this->clickVisibleButton($browser, 'Bearbeiten');
            $browser->waitFor('input[id="mountedActionSchema1.code"]', 15);
            $this->assertNotNull($browser->element('input[id="mountedActionSchema1.code"]')->getAttribute('disabled'));
        });
    }

    /** TCO-13 */
    public function test_delete_is_permanent_and_blocked_by_references(): void
    {
        $this->connect('organisationen');
        $this->connect('personen', writable: false);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/organisationen')->waitForText('Nordlicht Analytics AB', 15);

            // Verweisen Personen darauf, sperrt SAFT das Löschen mit Begründung.
            $this->openDetails($browser, 'ETH Zürich');
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitForText('endgültig aus der externen Datenbank gelöscht', 15);
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitForText('Personen (über „Arbeitgeber“)', 15);
            $this->assertTrue($this->fixture->connection()->table('forschung.organisationen')->where('name', 'ETH Zürich')->exists());

            $browser->visit('/admin/data/organisationen')->waitForText('Nordlicht Analytics AB', 15);
            $free = $this->fixture->connection()->table('forschung.organisationen')->insertGetId(['name' => 'Ohne Bezug AG', 'land' => 'CH', 'typ' => 'unternehmen']);
            $browser->refresh()->waitForText('Ohne Bezug AG', 15);
            $this->openDetails($browser, 'Ohne Bezug AG');
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitForText('endgültig', 15);
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitUntilMissingText('Ohne Bezug AG', 15);

            $this->assertFalse($this->fixture->connection()->table('forschung.organisationen')->where('id', $free)->exists());
        });
    }
}
