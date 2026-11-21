<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Report;
use App\Reports\ChartData;
use App\Reports\ReportResult;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ForschungDemoSeeder;
use Database\Seeders\LagerDemoSeeder;
use Database\Seeders\VertriebDemoSeeder;

/** Demo-Seeder Lager, Vertrieb und Forschung: Tabellen in SAFT selbst, Daten, Menügruppe, Auswertungen. */
class DemoSeedersTest extends DynamicDataTestCase
{
    public function test_lager_demo(): void
    {
        // Auswertung „Einkaufsvolumen“ filtert auf das laufende Jahr; die Demo-Bewegungen liegen 2026.
        $this->travelTo('2026-11-14 12:00:00');
        $this->seed(LagerDemoSeeder::class);
        $this->seed(LagerDemoSeeder::class);

        $this->assertCounts([
            'lager_lieferanten' => 6, 'lager_artikelgruppen' => 8, 'lager_artikel' => 20, 'lager_orte' => 6,
            'lager_bestaende' => 25, 'lager_mitarbeitende' => 6, 'lager_bewegungen' => 40, 'lager_messprotokoll' => 20,
        ]);
        $this->assertGroup(LagerDemoSeeder::GROUP['de'], 8, 7);
        $this->assertSame([
            'Lager führen' => ['Lagerbewegungen', 'Bestände', 'Messprotokoll'],
            'Stammdaten' => ['Artikel', 'Artikelgruppen', 'Lieferanten', 'Lagerorte', 'Mitarbeitende'],
            'Auswertungen' => ['Unter Mindestbestand', 'Unter Minimum (Anzahl)', 'Lagerwert', 'Artikel-Kalkulation', 'Bewegungskennzahlen', 'Einkaufsvolumen je Lieferant', 'Temperaturverlauf'],
        ], $this->submenus(LagerDemoSeeder::GROUP['de']), 'Untermenüs der Lager-Demo (B-066).');

        // Beschreibung (geht an den KI-Assistenten): Vorzeichen der Menge.
        $this->assertStringContainsString('negativ = Abgang', CustomTable::query()->where('name', 'lager_bewegungen')->sole()->getTranslation('description', 'de'));

        // Hierarchie über die Verknüpfung auf die eigene Tabelle.
        $groups = DynamicRecord::forTableName('lager_artikelgruppen');
        $paper = $groups->newQuery()->where('code', 'BUERO-PAP')->sole();
        $this->assertSame('BUERO', $groups->newQuery()->findOrFail($paper->obergruppe_id)->code);

        // Berechnete Felder ersetzen die Sicht „bestandsuebersicht“: Bestand und Reserve.
        $hammer = DynamicRecord::forTableName('lager_artikel')->newQuery()->where('artikelnummer', 'A-1001')->sole();
        $this->assertSame([30, 20], [(int) $hammer->bestand, (int) $hammer->reserve]);

        $belowMinimum = $this->runReport(LagerDemoSeeder::REPORT_BELOW_MINIMUM);
        // Reserve: V-3002 −60, P-5004 −50, K-4002 −20, B-2002 −5, P-5002 −4, A-1002 −3, A-1005 −1.
        $this->assertSame(['V-3002', 'P-5004', 'K-4002', 'B-2002', 'P-5002', 'A-1002', 'A-1005'], array_column($belowMinimum->rows, 'nr'));

        // Einkaufspreis zum Zeitpunkt der Bewegung (vom Artikel übernommen), Einkaufswert berechnet.
        $movements = DynamicRecord::forTableName('lager_bewegungen')->newQuery()->get();
        $this->assertSame(0, $movements->whereNull('einkaufspreis')->count());
        $items = DynamicRecord::forTableName('lager_artikel')->newQuery()->get()->keyBy('id');
        $suppliers = DynamicRecord::forTableName('lager_lieferanten')->newQuery()->pluck('name', 'id');
        $expected = [];

        foreach ($movements->where('art', 'Eingang') as $movement) {
            $this->assertEquals(round($movement->menge * (float) $items[$movement->artikel_id]->einkaufspreis, 2), (float) $movement->einkaufswert);
            $name = $suppliers[$items[$movement->artikel_id]->lieferant_id];
            $expected[$name] = round(($expected[$name] ?? 0) + (float) $movement->einkaufswert, 2);
        }

        arsort($expected);
        $purchases = $this->runReport(LagerDemoSeeder::REPORT_PURCHASES);
        $formatter = app(ValueFormatter::class);
        $actual = [];

        foreach ($purchases->rows as $row) {
            $actual[$formatter->format($row['lieferant'], $purchases->column('lieferant'))] = round((float) $row['summe'], 2);
        }

        $this->assertSame($expected, $actual, 'Summe der Eingänge × Einkaufspreis je Lieferant, absteigend.');
        $this->assertGreaterThan(1, count($actual));

        // Neue Bewegung übernimmt den Preis des Artikels; spätere Preisänderung wirkt nicht zurück.
        $hammerId = DynamicRecord::forTableName('lager_artikel')->newQuery()->where('artikelnummer', 'A-1001')->value('id');
        // Ohne Ereignisse für Plugins: das Beispiel-Plugin würde den Bestand mitbuchen, hier geht es nur um die Demo.
        $new = DynamicRecord::withoutPluginEvents(fn (): DynamicRecord => DynamicRecord::forTableName('lager_bewegungen')->create(['artikel_id' => $hammerId, 'lagerort_id' => $movements->first()->lagerort_id, 'art' => 'Eingang', 'menge' => 2, 'zeitpunkt' => '2026-11-10 08:00:00']));
        $this->assertEquals((float) $items[$hammerId]->einkaufspreis, (float) $new->fresh()->einkaufspreis);

        // Formeln: berechnete Spalten (Liste) und berechnete Werte (gruppiert).
        $calculation = $this->runReport(LagerDemoSeeder::REPORT_CALCULATION);
        $this->assertCount(20, $calculation->rows);
        $byNumber = array_column($calculation->rows, null, 'nr');
        $hammerItem = $items[$hammerId];
        $this->assertEqualsWithDelta((float) $hammerItem->verkaufspreis - (float) $hammerItem->einkaufspreis, (float) $byNumber['A-1001']['marge'], 0.0001);
        $this->assertEqualsWithDelta(30 * (float) $hammerItem->einkaufspreis, (float) $byNumber['A-1001']['lagerwert'], 0.0001);
        // Hammer: Bestand 30, Mindestbestand 10, Reserve 20.
        $this->assertEquals([0, 10, 20], [(float) $byNumber['A-1001']['nachbestellen'], (float) $byNumber['A-1001']['gedeckt'], (float) $byNumber['A-1001']['abstand']]);
        $this->assertEquals(60, (float) $byNumber['V-3002']['nachbestellen'], 'Reserve −60 → 60 nachbestellen.');
        $values = array_map('floatval', array_column($calculation->rows, 'lagerwert'));
        $sorted = $values;
        rsort($sorted);
        $this->assertSame($sorted, $values, 'Absteigend nach Lagerwert.');

        $figures = $this->runReport(LagerDemoSeeder::REPORT_MOVEMENT_FIGURES);
        $this->assertGreaterThan(1, count($figures->rows));
        $this->assertEqualsWithDelta(
            DynamicRecord::forTableName('lager_bewegungen')->newQuery()->get()->sum(fn ($m): float => $m->menge * (float) $m->einkaufspreis),
            array_sum(array_map('floatval', array_column($figures->rows, 'wert'))),
            0.01,
            'Summe über alle Gruppen = alle Bewegungen × Einkaufspreis.',
        );

        // Kennzahlen: eine Zahl.
        $stockValue = $this->runReport(LagerDemoSeeder::REPORT_STOCK_VALUE);
        $this->assertCount(1, $stockValue->rows);
        $this->assertEqualsWithDelta(array_sum(array_map('floatval', array_column($calculation->rows, 'lagerwert'))), (float) $stockValue->rows[0]['wert'], 0.01);
        $this->assertEquals(7, $this->runReport(LagerDemoSeeder::REPORT_BELOW_MINIMUM_COUNT)->rows[0]['anzahl'], 'Wie „Artikel unter Mindestbestand“.');

        $temperature = $this->runReport(LagerDemoSeeder::REPORT_TEMPERATURE);
        $chart = app(ChartData::class)->build($temperature, 'line');
        $this->assertSame(['Kühlraum 1', 'Tiefkühlraum'], array_column($chart['datasets'], 'label'));
        $this->assertCount(4, $chart['labels'], 'Vier Tage');
    }

    public function test_forschung_demo(): void
    {
        $this->seed(ForschungDemoSeeder::class);
        $this->seed(ForschungDemoSeeder::class);

        $this->assertCounts([
            'forschung_organisationen' => 8, 'forschung_personen' => 20, 'forschung_projekte' => 12, 'forschung_publikationen' => 25,
            'forschung_publikation_autoren' => 61, 'forschung_laender' => 4, 'forschung_importprotokoll' => 5,
        ]);
        $this->assertGroup(ForschungDemoSeeder::GROUP['de'], 7, 3);

        $publications = $this->runReport(ForschungDemoSeeder::REPORT_PUBLICATIONS);
        $this->assertSame(25, array_sum(array_column($publications->rows, 'anzahl')));
        $this->assertSame(['Artikel', 'Bericht', 'Buchkapitel', 'Konferenzbeitrag'], array_column(app(ChartData::class)->build($publications, 'bar')['datasets'], 'label'));

        // Kennzahl aus Werten: Budget je Publikation = Projektbudget ÷ Anzahl Publikationen des Projekts.
        $perPublication = $this->runReport(ForschungDemoSeeder::REPORT_BUDGET_PER_PUBLICATION);
        $this->assertNotEmpty($perPublication->rows);
        $projects = DynamicRecord::forTableName('forschung_projekte')->newQuery()->pluck('budget', 'id');
        $counts = DynamicRecord::forTableName('forschung_publikationen')->newQuery()->whereNotNull('projekt_id')->get()->countBy('projekt_id');

        foreach ($perPublication->rows as $row) {
            $this->assertEqualsWithDelta((float) $projects[$row['projekt']] / $counts[$row['projekt']], (float) $row['je_publikation'], 0.01);
        }

        $budget = $this->runReport(ForschungDemoSeeder::REPORT_BUDGET);
        $total = (float) DynamicRecord::forTableName('forschung_projekte')->newQuery()->sum('budget');
        $this->assertEqualsWithDelta($total, array_sum(array_column($budget->rows, 'summe')), 0.01);
    }

    public function test_vertrieb_demo_builds_on_the_lager_app_through_its_interface(): void
    {
        // Ohne Lager-Demo spielt der Vertrieb sie zuerst ein; zweimal einspielen dupliziert nichts.
        $this->seed(VertriebDemoSeeder::class);
        $this->seed(VertriebDemoSeeder::class);

        $this->assertCounts([
            'lager_artikel' => 20, 'vertrieb_kunden' => 12, 'vertrieb_events' => 8, 'vertrieb_bestellungen' => 10,
            'vertrieb_anfragen' => 20, 'vertrieb_anfragepositionen' => 43,
        ]);
        $this->assertGroup(VertriebDemoSeeder::GROUP['de'], 5, 3);
        $this->assertSame([
            'Verkauf' => ['Anfragen', 'Anfragepositionen', 'Bestellungen'],
            'Kunden und Events' => ['Kunden', 'Events'],
            'Auswertungen' => ['Anfragen je Status', 'Angefragte Artikel', 'Gewonnen je Kunde'],
        ], $this->submenus(VertriebDemoSeeder::GROUP['de']));

        // Eigene App, Artikel bleiben in der Lager-App und sind dort freigegeben.
        $sales = CustomTable::query()->where('name', 'vertrieb_anfragen')->sole()->app;
        $items = CustomTable::query()->with('fields')->where('name', 'lager_artikel')->sole();
        $this->assertSame('8f0c6a52-6d0e-4c8e-9a43-5b1c2f6e0a04', $sales->uuid);
        $this->assertNotSame($sales->id, $items->app_id);
        $this->assertTrue($items->is_shared);
        $this->assertEqualsCanonicalizing(VertriebDemoSeeder::SHARED_ITEM_FIELDS, $items->fields->where('is_shared', true)->pluck('name')->all());
        $this->assertSame(3, Report::query()->where('app_id', $sales->id)->count(), 'Auswertungen in der Vertriebs-App.');

        // Bestellung = Summe ihrer Anfrage (Positionen: Menge × Preis, berechnet).
        $orders = DynamicRecord::forTableName('vertrieb_bestellungen')->newQuery()->pluck('betrag', 'id');

        foreach (DynamicRecord::forTableName('vertrieb_anfragen')->newQuery()->whereNotNull('bestellung_id')->get() as $enquiry) {
            $this->assertEqualsWithDelta((float) $orders[$enquiry->bestellung_id], (float) $enquiry->summe, 0.001, $enquiry->betreff);
        }

        $status = $this->runReport(VertriebDemoSeeder::REPORT_STATUS);
        $this->assertSame(20, (int) array_sum(array_column($status->rows, 'anzahl')));
        $this->assertCount(6, $this->runReport(VertriebDemoSeeder::REPORT_WON)->rows, 'Kunden mit gewonnenen Anfragen.');
        $this->assertNotEmpty($this->runReport(VertriebDemoSeeder::REPORT_ITEMS)->rows);
    }

    public function test_all_three_demos_side_by_side(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(LagerDemoSeeder::class);
        $this->seed(ForschungDemoSeeder::class);

        // Shop und Lager haben je eine Tabelle „Artikel“ – getrennt durch das Präfix.
        $this->assertSame(['artikel', 'lager_artikel'], CustomTable::query()->where('name', 'like', '%artikel')->orderBy('name')->pluck('name')->all());
        $this->assertSame(
            ['Shop (Demo)', 'Lager (Demo)', 'Forschung (Demo)'],
            Menu::query()->whereNull('parent_id')->whereNull('route')->where('label->de', 'like', '%(Demo)')->orderBy('sort_order')->get()->map(fn (Menu $m): string => $m->getTranslation('label', 'de'))->all(),
        );
        $this->assertSame(11, Report::query()->count());
    }

    /** @param  array<string, int>  $counts */
    private function assertCounts(array $counts): void
    {
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DynamicRecord::forTableName($table)->newQuery()->count(), $table);
        }
    }

    private function assertGroup(string $label, int $tables, int $reports): void
    {
        $group = Menu::query()->whereNull('parent_id')->where('label->de', $label)->sole();
        // Direkt in der Gruppe und in ihren Untermenüs (Lager-Demo: Lager führen, Stammdaten, Auswertungen).
        $submenus = Menu::query()->where('parent_id', $group->id)->whereNull('route')->pluck('id')->all();
        $entries = Menu::query()->whereIn('parent_id', [$group->id, ...$submenus])->get();

        $this->assertSame($tables, $entries->where('route', DataMenu::ROUTE)->count());
        $this->assertSame(1, $entries->where('route', DataModelPage::ROUTE)->count());
        $this->assertSame($reports, $entries->where('route', ReportResource::VIEW_ROUTE)->count());
    }

    /** @return array<string, list<string>> Untermenü => Einträge, je nach Reihenfolge */
    private function submenus(string $label): array
    {
        $group = Menu::query()->whereNull('parent_id')->where('label->de', $label)->sole();

        return Menu::query()->where('parent_id', $group->id)->whereNull('route')->orderBy('sort_order')->get()
            ->mapWithKeys(static fn (Menu $submenu): array => [
                $submenu->getTranslation('label', 'de') => Menu::query()->where('parent_id', $submenu->id)->orderBy('sort_order')->get()
                    ->map(static fn (Menu $entry): string => $entry->getTranslation('label', 'de'))->all(),
            ])->all();
    }

    private function runReport(string $name): ReportResult
    {
        return app(ReportRunner::class)->run(Report::query()->where('name', $name)->sole(), $this->superAdmin());
    }
}
