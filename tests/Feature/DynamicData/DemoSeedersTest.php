<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Apps\Definition\AppInstaller;
use App\Documents\Templates\TemplateChecker;
use App\Documents\Templates\TemplateRenderer;
use App\DynamicData\DataMenu;
use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberSequences;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\RecordFile;
use App\Models\Report;
use App\Models\Role;
use App\Reports\ChartData;
use App\Reports\ReportResult;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use App\Services\DynamicSchemaService;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ForschungDemoSeeder;
use Database\Seeders\GitarrenshopDemoSeeder;
use Database\Seeders\LagerDemoSeeder;
use Database\Seeders\MusikschuleDemoSeeder;
use Database\Seeders\VertriebDemoSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

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

        // Kopiert man eine Anfrage, kommen die Positionen mit (B-123).
        $this->assertTrue(CustomTable::query()->where('name', 'vertrieb_anfragepositionen')->sole()->fields->firstWhere('name', 'anfrage_id')->optionsArray()['copy_with_parent'] ?? false);

        $status = $this->runReport(VertriebDemoSeeder::REPORT_STATUS);
        $this->assertSame(20, (int) array_sum(array_column($status->rows, 'anzahl')));
        $this->assertCount(6, $this->runReport(VertriebDemoSeeder::REPORT_WON)->rows, 'Kunden mit gewonnenen Anfragen.');
        $this->assertNotEmpty($this->runReport(VertriebDemoSeeder::REPORT_ITEMS)->rows);
    }

    /**
     * @param  Collection<int, DynamicRecord>  $receipts
     * @return list<string> höchste Belegnummer je Typ und Jahr
     */
    private function highestReceipts($receipts): array
    {
        return $receipts->pluck('belegnummer')->groupBy(static fn (string $n): string => substr($n, 0, 6))->map(static fn ($g): string => $g->max())->sort()->values()->all();
    }

    private function numberField(string $table, string $field): CustomField
    {
        return CustomField::query()->where('name', $field)->whereHas('custom_table', static fn ($q) => $q->where('name', $table))->sole();
    }

    /** Installationen vor N4 (Belegnummer als Text) stellt der Seeder um; die Nummern bleiben, die Zähler folgen. */
    public function test_gitarrenshop_receipt_number_is_converted_in_existing_installations(): void
    {
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $field = $this->numberField('gitarren_belege', 'belegnummer');
        app(DynamicSchemaService::class)->updateField($field, ['type' => 'string', 'options' => ['length' => 30]]);
        $this->assertSame([], app(NumberSequences::class)->counters($field->id));

        $this->seed(GitarrenshopDemoSeeder::class);

        $field = $field->fresh();
        $this->assertSame([FieldType::Number, GitarrenshopDemoSeeder::RECEIPT_NUMBER['pattern']], [$field->type, $field->optionsArray()['pattern']]);
        $this->assertCount(4, app(NumberSequences::class)->counters($field->id));
        $customer = DynamicRecord::forTableName('gitarren_kunden')->newQuery()->value('id');
        $this->assertSame('V-2026-003', DynamicRecord::forTableName('gitarren_belege')->create(['datum' => '2026-11-20', 'typ' => 'Einnahme', 'kunde_id' => $customer])->belegnummer);
    }

    public function test_gitarrenshop_demo_with_vat_files_and_instalments(): void
    {
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->seed(GitarrenshopDemoSeeder::class);

        $this->assertCounts(['gitarren_kunden' => 5, 'gitarren_lieferanten' => 4, 'gitarren_artikel' => 10, 'gitarren_belege' => 11, 'gitarren_buchungen' => 28, 'gitarren_zahlungen' => 13]);
        $this->assertGroup(GitarrenshopDemoSeeder::GROUP['de'], 6, 4);
        $this->assertSame(['Buchhaltung' => ['Belege', 'Buchungen', 'Zahlungen'], 'Stammdaten' => ['Artikel', 'Kunden', 'Lieferanten'],
            'Auswertungen' => ['Einnahmen und Ausgaben je Jahr', 'Gesamtsaldo', 'Alle Buchungen', 'Ein- und Ausgaben je Artikel']], $this->submenus(GitarrenshopDemoSeeder::GROUP['de']));

        // Einnahmen mit Kunde, Ausgaben mit Lieferant.
        $receipts = DynamicRecord::forTableName('gitarren_belege')->newQuery()->get();
        $this->assertSame(0, $receipts->where('typ', 'Einnahme')->whereNull('kunde_id')->count() + $receipts->where('typ', 'Ausgabe')->whereNull('lieferant_id')->count());
        $keller = DynamicRecord::forTableName('gitarren_kunden')->newQuery()->where('name', 'Keller')->sole();
        $this->assertSame((int) $keller->id, (int) $receipts->firstWhere('belegnummer', 'V-2026-002')->kunde_id);
        $this->assertSame('8f0c6a52-6d0e-4c8e-9a43-5b1c2f6e0a05', CustomTable::query()->where('name', 'gitarren_artikel')->sole()->app->uuid);

        // Belegnummer als Nummernkreis je Typ (N4): SAFT vergibt dieselben Nummern, die die Belegdateien tragen.
        foreach ($receipts as $receipt) {
            $this->assertSame(strtolower((string) $receipt->belegnummer).'.pdf', $receipt->dateien[0]['name'] ?? null);
        }

        $this->assertSame(['E-2025-004', 'E-2026-003', 'V-2025-002', 'V-2026-002'], $this->highestReceipts($receipts));
        $this->assertSame([
            ['group' => 'E', 'period' => '2025', 'value' => 4], ['group' => 'E', 'period' => '2026', 'value' => 3],
            ['group' => 'V', 'period' => '2025', 'value' => 2], ['group' => 'V', 'period' => '2026', 'value' => 2],
        ], app(NumberSequences::class)->counters($this->numberField('gitarren_belege', 'belegnummer')->id));

        // MWST aus dem gewählten Satz (if-Formel), Brutto, Einnahme bzw. Ausgabe nach der Art.
        $repair = DynamicRecord::forTableName('gitarren_buchungen')->newQuery()->where('text', 'Neue Bundierung')->sole();
        $this->assertSame(['9.72', '129.72', '0.00', '129.72', '-129.72'], array_map('strval', [$repair->mwst, $repair->brutto, $repair->einnahme, $repair->ausgabe, $repair->saldo]));

        // Artikel: Kosten (Warenwert, Zoll, Einfuhr-MWST, Reparatur) und Erlös; Ratenzahlung: drei Zahlungen, nichts offen.
        $tele = DynamicRecord::forTableName('gitarren_artikel')->newQuery()->where('inventarnummer', 'G-002')->sole();
        $this->assertSame(['348.08', '670.22', '322.14'], array_map('strval', [$tele->kosten, $tele->erloes, $tele->ergebnis]));
        $instalments = DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'V-2026-002')->sole();
        $this->assertSame(['583.74', '583.74', '0.00'], array_map('strval', [$instalments->summe, $instalments->bezahlt, $instalments->offen]));
        $this->assertSame('130.00', (string) DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'E-2026-003')->sole()->offen, 'Anzahlung: die Hälfte offen.');

        // Platzhalter-Bilder und PDF-Belege sind echte Dateien.
        $images = RecordFile::query()->where('field', 'bilder')->get();
        $this->assertSame(11, $images->count());
        $this->assertSame('image/png', $images->first()->mime);
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('local')->get(RecordFile::query()->where('field', 'dateien')->firstOrFail()->path));

        // Auswertungen: Jahre mit Saldo; Gesamtsaldo = Summe aller Salden.
        $years = $this->runReport(GitarrenshopDemoSeeder::REPORT_YEARS);
        $this->assertCount(2, $years->rows);
        $total = (float) DynamicRecord::forTableName('gitarren_buchungen')->newQuery()->sum('saldo');
        $this->assertEqualsWithDelta($total, (float) $this->runReport(GitarrenshopDemoSeeder::REPORT_BALANCE)->rows[0]['summe'], 0.001);
        $this->assertEqualsWithDelta($total, array_sum(array_map(static fn (array $r): float => (float) $r['saldo_summe'], $years->rows)), 0.001);
        $this->assertCount(28, $this->runReport(GitarrenshopDemoSeeder::REPORT_BOOKINGS)->rows);

        // Fachrolle mit allen Rechten der App; Belege kopieren nimmt die Buchungen mit.
        $role = Role::query()->where('name', GitarrenshopDemoSeeder::ROLE)->sole();
        $this->assertTrue($role->hasPermissionTo('data.gitarren_zahlungen.delete') && $role->hasPermissionTo('reports.view'));
        $this->assertSame(CustomTable::query()->where('name', 'gitarren_artikel')->sole()->app_id, $role->app_id);
        $this->assertTrue(CustomTable::query()->where('name', 'gitarren_buchungen')->sole()->fields->firstWhere('name', 'beleg_id')->optionsArray()['copy_with_parent'] ?? false);
    }

    public function test_musikschule_demo_installed_from_the_app_definition(): void
    {
        $this->superAdmin(); // Installieren braucht eine Person (Besitzerin der Auswertungen).
        $this->seed(MusikschuleDemoSeeder::class);
        $this->seed(MusikschuleDemoSeeder::class);

        $this->assertCounts([
            'musikschule_faecher' => 8, 'musikschule_kursarten' => 5, 'musikschule_semester' => 2, 'musikschule_raeume' => 5,
            'musikschule_lehrpersonen' => 6, 'musikschule_schueler' => 30, 'musikschule_kurse' => 22, 'musikschule_instrumente' => 10,
            'musikschule_mietvertraege' => 6, 'musikschule_veranstaltungen' => 3, 'musikschule_lektionen' => 200, 'musikschule_ferien' => 8,
        ]);
        $app = MusikschuleDemoSeeder::installedApp();
        $this->assertSame(['d5d9d05e-2fe0-4b57-bd17-24219b63beea', 'Musikschule (Demo)', true], [$app->uuid, $app->getTranslation('name', 'de'), $app->is_active]);
        $this->assertSame(21, CustomTable::query()->where('app_id', $app->id)->count());

        // Keine Lektion in den Ferien (Herbstferien 5.–16.10.2026); zehn je Kurs bis Ende Oktober.
        $dates = DynamicRecord::forTableName('musikschule_lektionen')->newQuery()->pluck('datum')->map(static fn ($d): string => substr((string) $d, 0, 10));
        $this->assertSame(0, $dates->filter(static fn (string $d): bool => $d >= '2026-10-05' && $d <= '2026-10-16')->count());
        $this->assertSame('2026-11-06', $dates->max());
        $this->assertSame(1, Role::query()->where('app_id', $app->id)->count());

        // Übernommene Werte: Tarif aus der Kursart, Einzelpreis aus dem Kurs, Stundensatz aus der Lehrperson; Honorar berechnet.
        $course = DynamicRecord::forTableName('musikschule_kurse')->newQuery()->where('bezeichnung', 'Klavier Laura')->sole();
        $this->assertSame('990.00', (string) $course->tarif);
        $line = DynamicRecord::forTableName('musikschule_rechnungspositionen')->newQuery()->where('kurs', $course->id)->sole();
        $this->assertSame(['990.00', '990.00'], [(string) $line->einzelpreis, (string) $line->betrag]);
        $lesson = DynamicRecord::forTableName('musikschule_lektionen')->newQuery()->where('kurs', $course->id)->firstOrFail();
        $this->assertSame(['110.00', '110.00'], [(string) $lesson->stundensatz, (string) $lesson->honorar]);

        // Geschwisterrabatt; Belegung zählt nur aktive Anmeldungen.
        $noah = DynamicRecord::forTableName('musikschule_rechnungspositionen')->newQuery()->where('text', 'Gitarre Noah (Noah Meier)')->sole();
        $this->assertSame('468.00', (string) $noah->betrag);
        $mia = DynamicRecord::forTableName('musikschule_kurse')->newQuery()->where('bezeichnung', 'Klavier Mia')->sole();
        $this->assertSame([1, 0], [(int) $mia->anzahl_anmeldungen, (int) $mia->freie_plaetze]);

        // Rechnungen: eine storniert (nichts offen), einige offen; sonst bezahlt + offen = Summe.
        $bills = DynamicRecord::forTableName('musikschule_rechnungen')->newQuery()->orderBy('id')->get();
        $this->assertSame(['R-2026-001', 'R-2026-002'], $bills->take(2)->pluck('rechnungsnummer')->all(), 'Nummernkreis (1.5) vergibt in der Reihenfolge der Anlage.');
        $this->assertSame(1, $bills->where('status', 'storniert')->count());
        // Entwurf fürs zweite Semester (1.6): ohne Nummer, nichts offen.
        $draft = $bills->sole(static fn ($b): bool => $b->status === 'entwurf');
        $this->assertSame([null, '0.00'], [$draft->rechnungsnummer, (string) $draft->offen]);
        $this->assertGreaterThan(0, (float) $draft->summe);
        $this->assertGreaterThan(0, $bills->where('status', 'versendet')->filter(static fn ($b): bool => (float) $b->offen > 0)->count());
        $this->assertSame('0.00', (string) $bills->firstWhere('status', 'storniert')->offen);
        $this->assertSame(0, $bills->where('status', 'versendet')->filter(static fn ($b): bool => abs((float) $b->summe - (float) $b->bezahlt - (float) $b->offen) > 0.001)->count());

        // Alle fünf Auswertungen laufen und liefern Zeilen.
        foreach (Report::query()->where('app_id', $app->id)->get() as $report) {
            $this->assertNotEmpty(app(ReportRunner::class)->run($report, $this->superAdmin())->rows, $report->name);
        }

        // Dokumentvorlage „Semesterrechnung“ (B-124): an die Kontaktperson, sonst an die Schülerin bzw. den Schüler.
        $template = DocumentTemplate::query()->where('uuid', MusikschuleDemoSeeder::INVOICE_TEMPLATE)->sole();
        $this->assertSame($app->id, $template->app_id);
        $this->assertSame([], app(TemplateChecker::class)->check($template->customTable, $template->body, $template->recipient, $template->file_name)['errors']);
        $bill = DynamicRecord::forTableName('musikschule_rechnungen')->newQuery()->findOrFail($line->rechnung);
        $document = app(TemplateRenderer::class)->render($template, $bill, $this->superAdmin());
        $this->assertStringContainsString('Klavier Laura', $document->html);
        $this->assertNotNull($document->recipient);
        $this->assertSame('Semesterrechnung-'.$bill->rechnungsnummer.'.pdf', $document->fileName);
        $this->assertSame('dokumente', $template->file_field, 'Ablage im Dateifeld „Dokumente“ der Rechnungen (1.2).');
        // E-Mail (1.3): an die Kontaktperson, sonst an die Schülerin bzw. den Schüler.
        $mail = app(TemplateRenderer::class)->mail($template, $bill, $this->superAdmin());
        $this->assertNotSame([], $mail['to'] ?? []);
        $this->assertStringContainsString((string) $bill->rechnungsnummer, $mail['subject']);
        $this->assertTrue(Role::query()->where('app_id', $app->id)->sole()->hasPermissionTo('documents.generate'));
    }

    public function test_musikschule_demo_updates_version_1_1_with_the_template_from_the_seeder(): void
    {
        $storage = sys_get_temp_dir().'/saft-musikschule-'.uniqid();
        $this->app->useStoragePath($storage);
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });

        // Stand 1.1: ohne Dateifeld „Dokumente“, ohne Teil „documents“; die Vorlage legte der Seeder an (ohne Ablage).
        $admin = $this->superAdmin();
        $old = app(MusikschuleDemoSeeder::class)->appDefinition();
        $template = $old['documents'][0];
        $old['app']['version'] = '1.1';
        $old['parts'] = ['reports', 'roles'];
        unset($old['documents']);
        $old['tables'] = array_map(static fn (array $t): array => $t['name'] === 'musikschule_rechnungen'
            ? [...$t, 'fields' => array_values(array_filter($t['fields'], static fn (array $f): bool => $f['name'] !== 'dokumente'))] : $t, $old['tables']);
        $old['roles'][0]['permissions'] = array_values(array_diff($old['roles'][0]['permissions'], ['documents.generate']));
        $app = app(AppInstaller::class)->install($old, null, $admin);
        $app->forceFill(['is_active' => true])->save();
        $table = CustomTable::query()->where('name', 'musikschule_rechnungen')->sole();
        DocumentTemplate::query()->create(['uuid' => $template['key'], 'app_id' => $app->id, 'custom_table_id' => $table->id,
            'name' => $template['name'], 'recipient' => $template['recipient'], 'body' => $template['body'], 'file_name' => $template['file_name']]);

        $this->seed(MusikschuleDemoSeeder::class);

        $this->assertSame('1.6', $app->fresh()->version);
        $this->assertNotNull(CustomField::query()->where('custom_table_id', $table->id)->where('name', 'dokumente')->first());
        $this->assertSame(['dokumente'], DocumentTemplate::query()->where('app_id', $app->id)->pluck('file_field')->all(), 'Nicht doppelt, mit Ablage.');
        $this->assertNotNull(DocumentTemplate::query()->where('app_id', $app->id)->value('email_to'), 'Mit E-Mail (1.3).');
        $this->assertTrue((bool) (DocumentTemplate::query()->where('app_id', $app->id)->sole()->qr_bill['enabled'] ?? false), 'Mit QR-Rechnung (1.4).');
        $this->assertTrue(Role::query()->where('app_id', $app->id)->sole()->hasPermissionTo('documents.generate'));
        File::deleteDirectory($storage);
    }

    public function test_musikschule_demo_updates_an_installed_app_of_version_1_0(): void
    {
        $storage = sys_get_temp_dir().'/saft-musikschule-'.uniqid();
        $this->app->useStoragePath($storage);
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });

        // Stand 1.0: ohne Tabelle Ferien, deren Menü und Rechte.
        $admin = $this->superAdmin();
        $old = app(MusikschuleDemoSeeder::class)->appDefinition();
        $old['app']['version'] = '1.0';
        $old['tables'] = array_values(array_filter($old['tables'], static fn (array $t): bool => $t['name'] !== 'musikschule_ferien'));
        $old['menus'] = array_values(array_filter($old['menus'], static fn (array $m): bool => ($m['target']['table'] ?? null) !== 'musikschule_ferien'));
        $old['roles'][0]['permissions'] = array_values(array_filter($old['roles'][0]['permissions'], static fn (string $p): bool => ! str_contains($p, 'musikschule_ferien')));
        app(AppInstaller::class)->install($old, null, $admin)->forceFill(['is_active' => true])->save();
        DynamicRecord::forTableName('musikschule_faecher')->create(['bezeichnung' => 'Harfe']);

        $this->seed(MusikschuleDemoSeeder::class);

        $app = MusikschuleDemoSeeder::installedApp();
        $this->assertSame(['1.6', 21], [$app->version, CustomTable::query()->where('app_id', $app->id)->count()]);
        $this->assertTrue(Menu::query()->where('app_id', $app->id)->where('label->de', 'Ferien')->exists());
        $this->assertTrue(Role::query()->where('app_id', $app->id)->sole()->hasPermissionTo('data.musikschule_ferien.update'));
        $this->assertCounts(['musikschule_ferien' => 8, 'musikschule_faecher' => 1]);
        File::deleteDirectory($storage);
    }

    public function test_musikschule_demo_update_to_1_5_turns_invoice_numbers_into_a_number_sequence(): void
    {
        $storage = sys_get_temp_dir().'/saft-musikschule-'.uniqid();
        $this->app->useStoragePath($storage);
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });

        // Stand 1.4: Rechnungsnummer als Pflicht-Textfeld mit Nummern aus dem Seeder.
        $admin = $this->superAdmin();
        $old = app(MusikschuleDemoSeeder::class)->appDefinition();
        $old['app']['version'] = '1.4';
        $old['tables'] = array_map(static fn (array $t): array => $t['name'] !== 'musikschule_rechnungen' ? $t : [...$t, 'fields' => array_map(
            static fn (array $f): array => $f['name'] === 'rechnungsnummer' ? [...$f, 'type' => 'string', 'options' => ['length' => 50], 'is_required' => true] : $f,
            $t['fields'],
        )], $old['tables']);
        app(AppInstaller::class)->install($old, null, $admin)->forceFill(['is_active' => true])->save();
        DynamicRecord::forTableName('musikschule_faecher')->create(['bezeichnung' => 'Harfe']);

        foreach (['R-2026-001', 'R-2026-005', 'Alt 17'] as $number) {
            app(RecordWriter::class)->create('musikschule_rechnungen', ['rechnungsnummer' => $number, 'datum' => '2026-09-01', 'status' => 'versendet']);
        }

        $this->seed(MusikschuleDemoSeeder::class);

        $field = CustomField::query()->where('name', 'rechnungsnummer')->whereHas('custom_table', fn ($q) => $q->where('name', 'musikschule_rechnungen'))->sole();
        $this->assertSame(['1.6', FieldType::Number], [MusikschuleDemoSeeder::installedApp()->version, $field->type]);
        $this->assertSame(['R-2026-001', 'R-2026-005', 'Alt 17'], DynamicRecord::forTableName('musikschule_rechnungen')->newQuery()->orderBy('id')->pluck('rechnungsnummer')->all());
        $this->assertSame(['2026' => 5], app(NumberSequences::class)->periods($field->id));
        $this->assertSame('R-2026-006', app(RecordWriter::class)->create('musikschule_rechnungen', ['datum' => '2026-11-26', 'status' => 'versendet'], WriteOptions::ui())->rechnungsnummer);
        File::deleteDirectory($storage);
    }

    public function test_musikschule_demo_update_to_1_6_assigns_invoice_numbers_only_outside_drafts(): void
    {
        $storage = sys_get_temp_dir().'/saft-musikschule-'.uniqid();
        $this->app->useStoragePath($storage);
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });

        // Stand 1.5: Nummer schon beim Anlegen, „offen“ auch bei Entwürfen, Auswertungen ohne stornierte.
        $admin = $this->superAdmin();
        $old = app(MusikschuleDemoSeeder::class)->appDefinition();
        $old['app']['version'] = '1.5';
        $old['tables'] = array_map(static fn (array $t): array => $t['name'] !== 'musikschule_rechnungen' ? $t : [...$t, 'fields' => array_map(static fn (array $f): array => match ($f['name']) {
            'rechnungsnummer' => [...$f, 'options' => array_diff_key($f['options'], [NumberCondition::OPTION => 0, NumberCondition::AST => 0])],
            'offen' => [...$f, 'options' => [...$f['options'], 'formula' => "if(status = 'storniert', 0, coalesce(summe, 0) - coalesce(bezahlt, 0))", 'ast' => null]],
            default => $f,
        }, $t['fields'])], $old['tables']);
        app(AppInstaller::class)->install($old, null, $admin)->forceFill(['is_active' => true])->save();
        DynamicRecord::forTableName('musikschule_faecher')->create(['bezeichnung' => 'Harfe']);

        $oldDraft = app(RecordWriter::class)->create('musikschule_rechnungen', ['datum' => '2026-09-01', 'status' => 'entwurf'], WriteOptions::ui());
        $this->assertSame('R-2026-001', $oldDraft->rechnungsnummer, '1.5: Nummer schon beim Anlegen.');

        $this->seed(MusikschuleDemoSeeder::class);

        $field = CustomField::query()->where('name', 'rechnungsnummer')->whereHas('custom_table', fn ($q) => $q->where('name', 'musikschule_rechnungen'))->sole();
        $this->assertSame(['1.6', "status <> 'entwurf'"], [MusikschuleDemoSeeder::installedApp()->version, $field->optionsArray()[NumberCondition::OPTION] ?? null]);
        $this->assertSame('R-2026-001', DynamicRecord::forTableName('musikschule_rechnungen')->newQuery()->findOrFail($oldDraft->id)->rechnungsnummer, 'Vergebene Nummern bleiben.');

        $draft = app(RecordWriter::class)->create('musikschule_rechnungen', ['datum' => '2026-11-26', 'status' => 'entwurf'], WriteOptions::ui());
        $this->assertNull($draft->rechnungsnummer);
        $this->assertSame('R-2026-002', app(RecordWriter::class)->update($draft, ['status' => 'versendet'], WriteOptions::ui())->rechnungsnummer);
        File::deleteDirectory($storage);
    }

    public function test_musikschule_demo_reloads_a_half_filled_demo(): void
    {
        $this->superAdmin();
        $this->seed(MusikschuleDemoSeeder::class);

        // Wie nach einem abgebrochenen Lauf: Finanzen fehlen.
        foreach (['musikschule_zahlungen', 'musikschule_rechnungspositionen', 'musikschule_rechnungen'] as $table) {
            DynamicRecord::forTableName($table)->newQuery()->delete();
        }

        $this->seed(MusikschuleDemoSeeder::class);

        $this->assertCounts(['musikschule_rechnungen' => 27, 'musikschule_schueler' => 30, 'musikschule_kurse' => 22, 'musikschule_ferien' => 8]);
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
        $this->assertTrue(CustomTable::query()->where('name', 'bestellpositionen')->sole()->fields->firstWhere('name', 'bestellung_id')->optionsArray()['copy_with_parent'] ?? false, 'Shop: Positionen werden mitkopiert.');
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
