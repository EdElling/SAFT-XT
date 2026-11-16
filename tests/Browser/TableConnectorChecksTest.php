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

/** Table-Connector, CHECK-Regeln der Datenbank (Bereich TCO). Schreibt in die externe Testdatenbank. */
class TableConnectorChecksTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    private const string ADMIN = 'admin@example.com';

    private const string FORM = 'mountedActionSchema0';

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
        $service = app(TableConnectorService::class);
        $connected = $service->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);

        return $writable ? $service->update($connected, ['allow_create' => true]) : $connected;
    }

    private function newProject(Browser $browser, string $begin, ?string $end): Browser
    {
        $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="'.self::FORM.'.kurzname"]', 15)
            ->type('input[id="'.self::FORM.'.kurzname"]', 'P-99')
            ->type('input[id="'.self::FORM.'.titel"]', 'Testprojekt')
            ->keys('input[id="'.self::FORM.'.beginn"]', $begin);

        if ($end !== null) {
            $browser->keys('input[id="'.self::FORM.'.ende"]', $end);
        }

        $this->pickOption($browser, self::FORM.'.projektleiter_id', 'Bruno');

        return $browser;
    }

    /** TCO-23 */
    public function test_value_list_from_check_constraint_is_a_selection(): void
    {
        $projects = $this->connect('projekte');
        $this->connect('personen', writable: false);

        $this->browse(function (Browser $browser) use ($projects): void {
            // Table-Connector: Werteliste in der Spaltenliste, Regeln der Datenbank mit Status.
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$projects->id}/edit")
                ->waitForText('Regeln der Datenbank', 15)
                ->assertSee('projekte_status_check');
            $this->scrollThroughPage($browser);
            $browser->assertSee('Werteliste (4)');

            // Neues Projekt (Nr. 13) erscheint in Schlüsselreihenfolge erst ab 25 Zeilen auf Seite 1.
            $browser->visit('/admin/data/projekte')->waitForText('Gletscherschmelze', 15);
            $this->showRowsPerPage($browser);
            $this->newProject($browser, '10112026', '30112027');
            $options = $browser->script("return [...document.querySelectorAll('select[id=\"".self::FORM.".status\"] option')].map(o => o.value).filter(v => v !== '');")[0];
            $this->assertSame(['geplant', 'laufend', 'abgeschlossen', 'abgebrochen'], $options);
            $browser->select('select[id="'.self::FORM.'.status"]', 'laufend');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Testprojekt', 15);
        });

        $this->assertSame('laufend', $this->fixture->connection()->table('forschung.projekte')->where('kurzname', 'P-99')->value('status'));
    }

    /** TCO-24 */
    public function test_comparison_of_two_fields_is_checked_in_the_form(): void
    {
        $this->connect('projekte');
        $this->connect('personen', writable: false);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/projekte')->waitForText('Gletscherschmelze', 15);
            $this->newProject($browser, '10112026', '01112026');
            $browser->select('select[id="'.self::FORM.'.status"]', 'geplant');
            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitForText('darf nicht vor „Beginn“ liegen.', 15)
                ->assertDontSee('Datenkonflikt');
        });

        $this->assertFalse($this->fixture->connection()->table('forschung.projekte')->where('kurzname', 'P-99')->exists());
    }

    /** TCO-25 */
    public function test_database_only_rule_is_named_in_the_message(): void
    {
        $organisations = $this->connect('organisationen');

        $this->browse(function (Browser $browser) use ($organisations): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-connector/{$organisations->id}/edit")
                ->waitForText('Regeln der Datenbank', 15)
                ->assertSee('organisationen_webseite_check')
                ->assertSee('nur Datenbank');

            $browser->visit('/admin/data/organisationen')->waitForText('ETH Zürich', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="'.self::FORM.'.name"]', 15)
                ->type('input[id="'.self::FORM.'.name"]', 'Verein FTP')
                ->type('input[id="'.self::FORM.'.land"]', 'CH');
            $browser->select('select[id="'.self::FORM.'.typ"]', 'unternehmen');
            $browser->type('input[id="'.self::FORM.'.webseite"]', 'ftp://example.org');
            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitForText('Ein Wert in „Webseite“ verletzt die Datenbankregel „organisationen_webseite_check“.', 15);
        });

        $this->assertFalse($this->fixture->connection()->table('forschung.organisationen')->where('name', 'Verein FTP')->exists());
    }
}
