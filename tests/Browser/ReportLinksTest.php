<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\ExternalData\Snapshots\SnapshotService;
use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\ReportLink;
use App\Models\User;
use App\Reports\Editor\FieldCatalog;
use App\Services\DynamicSchemaService;
use Database\Seeders\ExternalResearchDatabase;
use Laravel\Dusk\Browser;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\DuskTestCase;

/** Tabellenverknüpfungen: anlegen mit Treffer-Prüfung, im Auswertungs-Editor nutzen (Bereich RPT). */
class ReportLinksTest extends DuskTestCase
{
    use UsesExternalResearchDatabase;

    private const string ADMIN = 'admin@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $fixture = ExternalResearchDatabase::on(self::EXTERNAL_DATABASE);
        $fixture->reset();
        app(TableConnectorService::class)->connect($this->researchConnection(), ExternalResearchDatabase::SCHEMA, 'personen', ['name' => 'ext_personen']);

        app(DynamicSchemaService::class)->createTable(['name' => 'kontakte', 'label' => 'Kontakte'], [
            ['name' => 'betreff', 'label' => 'Betreff', 'type' => 'string'],
            ['name' => 'person_nr', 'label' => 'Person-Nr.', 'type' => 'integer'],
        ]);

        foreach ([['A', 1], ['B', 2], ['C', 9999]] as [$subject, $person]) {
            DynamicRecord::forTableName('kontakte')->create(['betreff' => $subject, 'person_nr' => $person]);
        }

        app(SnapshotService::class)->refresh(CustomTable::query()->where('name', 'ext_personen')->sole(), User::query()->where('email', self::ADMIN)->sole());
    }

    protected function externalDatabaseName(): string
    {
        return self::EXTERNAL_DATABASE;
    }

    /** RPT-16 */
    public function test_link_is_created_with_coverage_and_used_in_the_report_editor(): void
    {
        $person1 = (string) ExternalResearchDatabase::on(self::EXTERNAL_DATABASE)->connection()
            ->table(ExternalResearchDatabase::SCHEMA.'.personen')->where('id', 1)->value('nachname');

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/report-links/create')
                ->waitFor('[id="form.label.de"]', 15)
                ->type('[id="form.label.de"]', 'Person (Forschung)');
            $this->pickOption($browser, 'form.source_table_id', 'Kontakte');
            $this->pickOption($browser, 'form.source_field_id', '(person_nr)');
            $this->pickOption($browser, 'form.target_table_id', 'ext_personen');
            $this->pickOption($browser, 'form.target_field', '(id)');
            $browser->waitForText('2 von 3 Zeilen mit Wert finden ein Ziel (67 %).', 15);
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Person (Forschung)', 15);
        });

        $link = ReportLink::query()->sole();
        $kontakte = (int) CustomTable::query()->where('name', 'kontakte')->value('id');
        $name = (int) CustomTable::query()->where('name', 'ext_personen')->sole()->fields()->where('name', 'nachname')->value('id');
        $ref = FieldCatalog::ref([-$link->id], $name);

        $this->browse(function (Browser $browser) use ($kontakte, $ref, $person1): void {
            $browser->visit('/admin/reports/create')
                ->waitFor('[data-report-designer]', 15)
                ->click('[data-base-picker] > button')
                ->waitFor('[data-base-filter]', 5)
                ->click('[data-base-option="'.$kontakte.'"]')
                ->waitFor('[data-zone="catalog"]', 15)
                ->assertPresent("[data-zone=\"catalog\"] [data-ref=\"{$ref}\"]");
            $browser->click("[data-zone=\"catalog\"] [data-ref=\"{$ref}\"] button")->pause(400);
            $this->clickVisibleButton($browser, 'Spalten der Liste')
                ->waitForText('Liste: Person (Forschung) › Nachname', 15)
                ->assertSeeIn('[data-report-table]', $person1)
                ->assertSee('Datenstand: Personen');
        });
    }

    /** KAS-22 */
    public function test_table_link_shows_saft_contacts_below_an_external_person(): void
    {
        $people = CustomTable::query()->where('name', 'ext_personen')->sole();
        $contacts = CustomTable::query()->where('name', 'kontakte')->sole();
        $link = ReportLink::create([
            'label' => ['de' => 'Person (Forschung)'],
            'source_table_id' => $contacts->id,
            'source_field_id' => (int) $contacts->fields()->where('name', 'person_nr')->value('id'),
            'target_table_id' => $people->id,
            'target_field_id' => (int) $people->fields()->where('name', 'id')->value('id'),
        ]);
        app(TableConnectorService::class)->update($people, ['detail_field_ids' => [-$link->id]]);
        $person1 = (string) ExternalResearchDatabase::on(self::EXTERNAL_DATABASE)->connection()
            ->table(ExternalResearchDatabase::SCHEMA.'.personen')->where('id', 1)->value('nachname');

        $this->browse(function (Browser $browser) use ($person1): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/ext_personen')->waitForText($person1, 15);
            $this->clickRow($browser, $person1)
                ->waitForText('Kontakte (über Tabellenverknüpfung „Person (Forschung)“)', 15)
                ->waitForText('A', 5)
                ->assertDontSee('9999');

            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="mountedActionSchema0.betreff"]', 15)
                ->assertMissing('[id="mountedActionSchema0.person_nr"]')
                ->type('input[id="mountedActionSchema0.betreff"]', 'Neuer Kontakt');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Neuer Kontakt', 15);
        });

        $this->assertSame(1, (int) DynamicRecord::forTable($contacts)->newQuery()->where('betreff', 'Neuer Kontakt')->value('person_nr'));
    }
}
