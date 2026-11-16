<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\ExternalData\Snapshots\SnapshotService;
use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\User;
use Database\Seeders\ExternalResearchDatabase;
use Laravel\Dusk\Browser;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\DuskTestCase;

/** Datenstände angebundener Tabellen im Auswertungs-Editor und im Table-Connector (Bereiche RPT, TCO). */
class ReportSnapshotsTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    private const string ADMIN = 'admin@example.com';

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        ExternalResearchDatabase::on(self::EXTERNAL_DATABASE)->reset();
        $this->connection = $this->researchConnection();
    }

    protected function externalDatabaseName(): string
    {
        return self::EXTERNAL_DATABASE;
    }

    private function connect(string $table): CustomTable
    {
        return app(TableConnectorService::class)->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);
    }

    private function openPicker(Browser $browser): Browser
    {
        return $browser->visit('/admin/reports/create')
            ->waitFor('[data-report-designer]', 15)
            ->click('[data-base-picker] > button')
            ->waitFor('[data-base-filter]', 5);
    }

    /** RPT-12 */
    public function test_picker_marks_connected_tables_and_creates_the_snapshot_on_selection(): void
    {
        $people = $this->connect('personen');
        $this->connect('organisationen');

        $this->browse(function (Browser $browser) use ($people): void {
            $this->openPicker($this->login($browser, self::ADMIN))
                ->assertVisible('[data-base-option="'.$people->id.'"][data-kind="external"]')
                ->assertSeeIn('[data-base-option="'.$people->id.'"]', 'noch kein Datenstand')
                ->type('[data-base-filter]', 'xyz')
                ->waitForText('Keine Tabelle gefunden', 5)
                ->clear('[data-base-filter]')
                ->type('[data-base-filter]', 'perso')
                ->pause(300)
                ->click('[data-base-option="'.$people->id.'"]')
                ->waitFor('[data-zone="catalog"]', 30)
                ->assertSeeIn('[data-base-selected]', 'Personen')
                // Verknüpfte Organisationen haben ihren Stand mitbekommen: ihre Felder sind wählbar.
                ->assertSeeIn('.catalog', 'Arbeitgeber')
                ->click('[data-base-picker] > button')
                ->waitFor('[data-base-filter]', 5)
                ->assertVisible('[data-base-option="'.$people->id.'"][data-kind="snapshot"]')
                ->assertSeeIn('[data-base-option="'.$people->id.'"]', 'Datenstand '.now()->format('d.m.Y'));
        });

        $this->assertNotNull(CustomTable::query()->where('name', 'organisationen')->sole()->latestSnapshot());
    }

    /** RPT-13 */
    public function test_stale_snapshot_asks_before_refreshing(): void
    {
        $people = $this->connect('personen');
        $old = app(SnapshotService::class)->refresh($people, User::query()->where('email', self::ADMIN)->sole());
        $old->update(['finished_at' => now()->subDays(2)]);

        $this->browse(function (Browser $browser) use ($people, $old): void {
            $this->openPicker($this->login($browser, self::ADMIN))
                ->click('[data-base-option="'.$people->id.'"]')
                ->waitForText('Datenstand erneuern?', 15)
                ->assertSee('Personen – Stand vom '.$old->finished_at->format('d.m.Y'));
            $this->clickVisibleButton($browser, 'Neuen Stand erzeugen')
                ->waitFor('[data-zone="catalog"]', 30)
                ->waitForText('Datenstand erzeugt: Personen', 15);
        });

        $this->assertNotSame($old->id, $people->latestSnapshot()?->id);
    }

    /** TCO-27 */
    public function test_snapshot_tab_in_the_table_connector(): void
    {
        $people = $this->connect('personen');

        $this->browse(function (Browser $browser) use ($people): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-connector/'.$people->id.'/edit')
                ->waitForText('Datenstände', 15);
            // Der Reiter lädt erst, wenn er im Bild ist.
            $this->clickVisibleButton($browser, 'Datenstände')->pause(300)
                ->script('window.scrollTo(0, document.body.scrollHeight);');
            $browser->waitForText('Noch kein Datenstand', 15);
            $this->clickVisibleButton($browser, 'Jetzt aktualisieren')
                ->waitForText('Datenstand gezogen: 20 Zeilen', 30)
                ->waitForText('vollständig', 15);
        });

        $this->assertSame(20, $people->latestSnapshot()?->row_count);
    }
}
