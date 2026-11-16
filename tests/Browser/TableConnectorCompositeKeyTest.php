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

/** Table-Connector, zusammengesetzte Schlüssel (Bereich TCO). Schreibt in die externe Testdatenbank. */
class TableConnectorCompositeKeyTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    private const string ADMIN = 'admin@example.com';

    private const string PUBLICATION = 'Studie 1: Gletscherschmelze';

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

    private function connect(string $table, string $schema = ExternalResearchDatabase::SCHEMA): CustomTable
    {
        return app(TableConnectorService::class)->connect($this->connection, $schema, $table, ['name' => $table]);
    }

    /** Publikationen und Personen angebunden, Zuordnungen als Details unter Publikationen. */
    private function connectAssignments(): CustomTable
    {
        $publications = $this->connect('publikationen');
        $this->connect('personen');
        $authors = $this->connect('publikation_autoren');
        app(TableConnectorService::class)->update($publications->fresh(), ['detail_field_ids' => [
            (int) $authors->fields()->where('name', 'publikation_id')->value('id'),
        ]]);

        return $authors;
    }

    private function assignment(int $person): ?object
    {
        $publication = $this->fixture->connection()->table('forschung.publikationen')->where('titel', 'like', self::PUBLICATION.'%')->value('id');

        return $this->fixture->connection()->table('forschung.publikation_autoren')->where(['publikation_id' => $publication, 'person_id' => $person])->first();
    }

    /** TCO-20 */
    public function test_composite_key_is_shown_and_assignment_created_under_publication(): void
    {
        $authors = $this->connectAssignments();
        $log = app(TableConnectorService::class)->update($this->connect('importprotokoll', 'public'), ['key_columns' => ['zeitpunkt', 'quelle']]);
        $person = (int) $this->fixture->connection()->table('forschung.personen')->where('nachname', 'Zimmermann')->value('id');
        $this->assertNull($this->assignment($person), 'Testdaten: Person ist bereits zugeordnet.');

        $this->browse(function (Browser $browser) use ($authors, $log): void {
            // Ohne Garantie der Datenbank kein Schreiben, auch mit von Hand gewähltem Schlüssel.
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$log->id}/edit")
                ->waitForText('Schreiben', 15)
                ->assertSee('garantiert die Eindeutigkeit des Schlüssels nicht');
            $this->assertNotNull($browser->element('[id="form.allow_create"]')->getAttribute('disabled'));

            // Zuordnungstabelle: beide Schlüsselspalten vorbelegt, Schreiben freischalten.
            $browser->visit("/admin/table-connector/{$authors->id}/edit")->waitForText('Schreiben', 15);
            $keys = (string) $browser->script("return document.getElementById('form.key_columns')?.closest('.fi-fo-field')?.innerText ?? '';")[0];
            $this->assertStringContainsString('publikation_id', $keys);
            $this->assertStringContainsString('person_id', $keys);
            $browser->click('[id="form.allow_create"]')
                ->click('[id="form.allow_update"]')
                ->click('[id="form.allow_delete"]')
                ->press('Speichern')->waitForText('Gespeichert', 15);

            // Unter einer Publikation eine Person zuordnen.
            // Sortiert nach UUID: alle 25 Publikationen auf einer Seite.
            $browser->visit('/admin/data/publikationen')->waitForText('Studie', 15);
            $this->showRowsPerPage($browser)->waitForText(self::PUBLICATION, 15);
            $this->clickRow($browser, self::PUBLICATION);
            $browser->waitForText('Publikation autoren (über „Publikation“)', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="mountedActionSchema0.reihenfolge"]', 15)
                ->assertMissing('[id="mountedActionSchema0.publikation_id"]');
            $this->pickOption($browser, 'mountedActionSchema0.person_id', 'Paul');
            $browser->type('input[id="mountedActionSchema0.reihenfolge"]', '5');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Paul', 15);
        });

        $this->assertSame(5, (int) $this->assignment($person)?->reihenfolge);
    }

    /** TCO-21 */
    public function test_assignment_is_edited_and_deleted_via_details(): void
    {
        $authors = $this->connectAssignments();
        app(TableConnectorService::class)->update($authors->fresh(), ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true]);
        $publication = $this->fixture->connection()->table('forschung.publikationen')->where('titel', 'like', self::PUBLICATION.'%')->value('id');
        $first = $this->fixture->connection()->table('forschung.publikation_autoren')->where('publikation_id', $publication)->orderBy('reihenfolge')->first();
        $name = (string) $this->fixture->connection()->table('forschung.personen')->where('id', $first->person_id)->value('vorname');

        $this->browse(function (Browser $browser) use ($name): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/publikationen')->waitForText('Studie', 15);
            $this->showRowsPerPage($browser)->waitForText(self::PUBLICATION, 15);
            $this->clickRow($browser, self::PUBLICATION);
            $browser->waitForText('Publikation autoren (über „Publikation“)', 15);
            $this->openDetails($browser, $name);

            // Bearbeiten: Person (Schlüssel) gesperrt, Reihenfolge änderbar.
            $this->clickVisibleButton($browser, 'Bearbeiten');
            $browser->waitFor('input[id="mountedActionSchema1.reihenfolge"]', 15);
            $this->assertNotNull($browser->element('[id="mountedActionSchema1.person_id"]')->getAttribute('disabled'));
            $this->emptyField($browser, 'input[id="mountedActionSchema1.reihenfolge"]');
            $browser->type('input[id="mountedActionSchema1.reihenfolge"]', '8');
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->pause(1500);
        });

        $this->assertSame(8, (int) $this->assignment((int) $first->person_id)?->reihenfolge);
        // Nur diese Zuordnung geändert.
        $this->assertSame(1, $this->fixture->connection()->table('forschung.publikation_autoren')->where('reihenfolge', 8)->count());

        $this->browse(function (Browser $browser) use ($name): void {
            // Die Wahl von 25 Zeilen gilt in dieser Sitzung weiter.
            $browser->visit('/admin/data/publikationen')->waitForText(self::PUBLICATION, 15);
            $this->clickRow($browser, self::PUBLICATION);
            $browser->waitForText('Publikation autoren (über „Publikation“)', 15);
            $this->openDetails($browser, $name);
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitForText('endgültig', 15);
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->pause(1500);
        });

        $this->assertNull($this->assignment((int) $first->person_id));
    }

    /** TCO-22 */
    public function test_assignment_created_in_list_and_change_opened_from_dashboard(): void
    {
        $authors = $this->connectAssignments();
        $service = app(TableConnectorService::class);
        $service->update($authors->fresh(), ['allow_create' => true]);
        // Wie in älteren Konfigurationen: publikation_id steht auf „Nur lesen“.
        $service->updateField($authors->fields()->where('name', 'publikation_id')->firstOrFail(), ['is_read_only' => true]);
        $person = (int) $this->fixture->connection()->table('forschung.personen')->where('nachname', 'Zimmermann')->value('id');

        $this->browse(function (Browser $browser): void {
            // Direkt in der Liste: Publikation und Person sind wählbar.
            $this->login($browser, self::ADMIN)->visit('/admin/data/publikation_autoren')->waitForText('Neuer Datensatz', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="mountedActionSchema0.reihenfolge"]', 15);
            $this->pickOption($browser, 'mountedActionSchema0.publikation_id', self::PUBLICATION);
            $this->pickOption($browser, 'mountedActionSchema0.person_id', 'Paul');
            $browser->type('input[id="mountedActionSchema0.reihenfolge"]', '6');
            $this->clickVisibleButton($browser, 'Erstellen')->pause(1500)->assertDontSee('Datenkonflikt');

            // „Meine letzten Änderungen“: Die neue Zuordnung (zusammengesetzter Schlüssel) öffnet die Liste statt 404.
            $browser->visit('/admin')->waitForText('Meine letzten Änderungen', 15);
            // Objekt lesbar statt kodierter Kennung.
            $browser->assertSee('Publikation autoren #(')->assertDontSee('#row-');
            $browser->click('.fi-wi-table tr.fi-ta-row a[href*="/admin/data/publikation_autoren"]')
                ->waitForLocation('/admin/data/publikation_autoren', 15)
                ->waitForText('Neuer Datensatz', 15);
        });

        $this->assertSame(6, (int) $this->assignment($person)?->reihenfolge);
    }
}
