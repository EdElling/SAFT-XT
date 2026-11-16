<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Import\ImportAnalysis;
use App\DynamicData\Import\ImportAnalyzer;
use App\DynamicData\Import\ImportFormat;
use App\DynamicData\Schema\FieldType;
use App\Services\DynamicSchemaService;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/** Prüfung von Import-Dateien: saubere Liste, Feldnamen, Typen, präzise Meldungen. */
class ImportAnalyzerTest extends DynamicDataTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->dir = sys_get_temp_dir().'/saft-import-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_clean_german_csv_from_excel(): void
    {
        // Wie deutsches Excel speichert: Semikolon, Windows-1252.
        $csv = "ID;Name;Straße;PLZ;Umsatz;Seit;Aktiv;Order\r\n"
            ."7;Müller AG;Hauptstraße 1;01067;1.234,50;01.03.2020;ja;A-1\r\n"
            ."8;Schmidt;Ring 2;80331;99;15.11.2021;nein;B-2\r\n"
            ."\r\n";
        $analysis = $this->analyze($this->file('Kunden Liste 2026.csv', mb_convert_encoding($csv, 'Windows-1252', 'UTF-8')));

        $this->assertTrue($analysis->isImportable(), implode("\n", $analysis->messages()));
        $this->assertSame([ImportFormat::Csv, ';', 'Windows-1252', 2], [$analysis->format, $analysis->delimiter, $analysis->encoding, $analysis->rowCount]);
        $this->assertSame('kunden_liste_2026', $analysis->tableName);
        $this->assertSame(
            ['id_import', 'name', 'strasse', 'plz', 'umsatz', 'seit', 'aktiv', 'order_import'],
            array_map(fn ($c): string => $c->name, $analysis->columns),
        );
        $this->assertSame('Straße', $analysis->column('strasse')->header);
        $this->assertSame(
            [FieldType::Integer, FieldType::String, FieldType::String, FieldType::String, FieldType::Decimal, FieldType::Date, FieldType::Boolean, FieldType::String],
            array_map(fn ($c): FieldType => $c->type, $analysis->columns),
        );
        $this->assertSame('„ID“ ist ein Systemfeld und wird als id_import übernommen.', $analysis->column('id_import')->notes[0]);
        $this->assertStringContainsString('reservierter Name', $analysis->column('order_import')->notes[0]);
        $this->assertStringContainsString('führender Null', $analysis->column('plz')->notes[0]);
        $this->assertSame('1234.50', $analysis->column('umsatz')->convert('1.234,50'));
    }

    public function test_english_csv_with_bom_and_unique_table_name(): void
    {
        app(DynamicSchemaService::class)->createTable(['name' => 'orders', 'label' => 'Orders']);
        $analysis = $this->analyze($this->file('Orders.csv', "\u{FEFF}Order No,Amount,Date\n1001,\"1,234.50\",2026-01-31\n1002,7.25,2026-02-01\n"));

        $this->assertTrue($analysis->isImportable(), implode("\n", $analysis->messages()));
        $this->assertSame([',', 'UTF-8', 'orders_2'], [$analysis->delimiter, $analysis->encoding, $analysis->tableName]);
        $this->assertSame(['order_no', 'amount', 'date'], array_map(fn ($c): string => $c->name, $analysis->columns));
        $this->assertSame([FieldType::Integer, FieldType::Decimal, FieldType::Date], array_map(fn ($c): FieldType => $c->type, $analysis->columns));
    }

    public function test_untidy_csv_lists_every_problem_precisely(): void
    {
        $csv = "Name;;Name;Kunden Nr;Kunden-Nr.;#\n"
            ."a;b;c;d;e;f\n"
            ."\n"
            ."a;b;c;d;e\n"
            ."a;b;c;d;e;f\n"
            ."\n\n";
        $messages = $this->analyze($this->file('liste.csv', $csv))->messages();

        $this->assertSame([
            'Zeile 3 ist leer, danach folgen weitere Daten. Eine Liste darf keine Leerzeilen enthalten.',
            'Zeile 4 hat 5 statt 6 Spalten (Trennzeichen oder Anführungszeichen im Text?).',
            'Spalte B hat keinen Titel.',
            'Spalte F: Aus dem Titel „#“ lässt sich kein Feldname bilden. Bitte einen Titel mit Buchstaben oder Ziffern verwenden.',
            'Der Titel „Name“ kommt mehrfach vor (Spalten A, C).',
            'Die Spalten D, E ergeben denselben Feldnamen „kunden_nr“. Bitte die Titel eindeutig machen.',
        ], $messages);
    }

    public function test_many_identical_problems_are_summarised(): void
    {
        $csv = "a;b\n".str_repeat("1\n", 14);
        $analysis = $this->analyze($this->file('viele.csv', $csv));

        $this->assertCount(11, $analysis->errors());
        $this->assertSame('… und 4 weitere Stellen mit demselben Problem.', $analysis->messages()[10]);
    }

    public function test_header_must_be_in_the_first_row(): void
    {
        $this->assertSame(['header_not_first_row'], $this->analyze($this->xlsx('leer.xlsx', [[], ['A', 'B'], [1, 2]]))->codes());

        $title = $this->analyze($this->xlsx('titel.xlsx', [['Kundenliste 2026'], ['Name', 'Ort'], ['A', 'B']]));
        $this->assertFalse($title->isImportable());
        $this->assertContains('title_row', $title->codes());
        $this->assertContains('Zeile 2 enthält in Spalte B einen Wert, aber die Spalte hat keinen Titel.', $title->messages());
    }

    public function test_excel_types_formulas_and_errors(): void
    {
        $path = $this->xlsx('typen.xlsx', [
            ['Menge', 'Preis', 'Datum', 'Zeitpunkt', 'Bezahlt', 'Summe', 'Quote', 'PLZ'],
            [3, 4.5, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-01 10:30'), true, '=A2*B2', '=1/0', '01067'],
            [5, 2.25, new \DateTimeImmutable('2026-03-02'), new \DateTimeImmutable('2026-03-02 11:00'), false, '=A3*B3', 7, '80331'],
        ]);
        // OpenSpout schreibt Formeln ohne Ergebnis; Excel speichert es mit (<v>), Fehler mit t="e".
        $this->patchSheet($path, fn (string $xml): string => str_replace(
            ['<f>A2*B2</f>', '<f>A3*B3</f>', '<c r="G2" s="0"><f>1/0</f>'],
            ['<f>A2*B2</f><v>13.5</v>', '<f>A3*B3</f><v>11.25</v>', '<c r="G2" s="0" t="e"><f>1/0</f><v>#DIV/0!</v>'],
            $xml,
        ));

        $analysis = $this->analyze($path);

        $this->assertSame(['Zelle G2 enthält einen Fehler (=1/0).'], $analysis->messages());
        $this->assertSame(
            [FieldType::Integer, FieldType::Decimal, FieldType::Date, FieldType::DateTime, FieldType::Boolean, FieldType::Decimal, FieldType::Integer, FieldType::String],
            array_map(fn ($c): FieldType => $c->type, $analysis->columns),
        );
        $this->assertSame(['precision' => 12, 'scale' => 2], $analysis->column('summe')->options);
    }

    public function test_merged_cells_and_sheet_selection(): void
    {
        $merged = $this->xlsx('verbunden.xlsx', [['Name', 'Ort'], ['A', 'B']], merge: [0, 2, 1, 2]);
        $this->assertSame(['Die Tabelle enthält verbundene Zellen (A2:B2). Bitte die Verbindung aufheben.'], $this->analyze($merged)->messages());

        $path = $this->xlsx('mappe.xlsx', [['Titel'], ['x']], sheets: ['Zweites Blatt' => [['Artikel', 'Preis'], ['Tisch', 99.9]]]);
        $first = $this->analyze($path);
        $this->assertSame(['Tabelle1', 'Zweites Blatt'], $first->sheets);
        $this->assertSame('Tabelle1', $first->sheet);

        $second = app(ImportAnalyzer::class)->analyze($path, 'mappe.xlsx', 'Zweites Blatt');
        $this->assertSame(['artikel', 'preis'], array_map(fn ($c): string => $c->name, $second->columns));
        $this->assertSame('zweites_blatt', $second->tableName, 'Bei mehreren Blättern heißt die Tabelle wie das Blatt.');
    }

    public function test_warnings_do_not_block_the_import(): void
    {
        $analysis = $this->analyze($this->file('summe.csv', "Artikel;Betrag\nTisch;10\nStuhl;5\nSumme;15\n"));
        $this->assertTrue($analysis->isImportable());
        $this->assertSame(['Zeile 4 („Summe“) sieht nach einer Summenzeile aus. Sie würde als normaler Datensatz importiert.'], $analysis->messages());

        $empty = $this->analyze($this->file('nur-kopf.csv', "Artikel;Betrag\n"));
        $this->assertTrue($empty->isImportable());
        $this->assertSame(['no_data'], $empty->codes());
        $this->assertSame(FieldType::String, $empty->columns[1]->type);
    }

    public function test_limits_and_unreadable_files(): void
    {
        $this->assertSame(['unsupported_format'], $this->analyze($this->file('daten.ods', 'x'))->codes());
        $this->assertSame(['unreadable'], $this->analyze($this->file('kaputt.xlsx', 'kein Excel'))->codes());

        config(['dynamic_data.import.max_rows' => 2]);
        $this->assertSame(['too_many_rows'], $this->analyze($this->file('lang.csv', "a;b\n1;2\n3;4\n5;6\n"))->codes());

        config(['dynamic_data.import.max_file_mb' => 0]);
        $this->assertSame(['Die Datei ist zu gross (höchstens 0 MB).'], $this->analyze($this->file('gross.csv', "a;b\n1;2\n"))->messages());
    }

    private function analyze(string $path): ImportAnalysis
    {
        return app(ImportAnalyzer::class)->analyze($path, basename($path));
    }

    private function file(string $name, string $content): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $content);

        return $path;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  array<string, list<list<mixed>>>  $sheets  weitere Blätter
     * @param  array{0: int, 1: int, 2: int, 3: int}|null  $merge  Spalte/Zeile oben links, Spalte/Zeile unten rechts
     */
    private function xlsx(string $name, array $rows, array $sheets = [], ?array $merge = null): string
    {
        $options = new Options;

        if ($merge !== null) {
            $options->mergeCells(...$merge);
        }

        $writer = new Writer($options);
        $writer->openToFile($path = $this->dir.'/'.$name);
        $writer->getCurrentSheet()->setName('Tabelle1');

        foreach ([$rows, ...array_values($sheets)] as $index => $sheetRows) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent()->setName(array_keys($sheets)[$index - 1]);
            }

            foreach ($sheetRows as $row) {
                // Datumszellen tragen wie in Excel ein Datumsformat, sonst sind es bloße Zahlen.
                $writer->addRow(new Row(array_map(fn (mixed $value): Cell => Cell::fromValue($value, $value instanceof \DateTimeInterface
                    ? (new Style)->setFormat($value->format('H:i') === '00:00' ? 'dd.mm.yyyy' : 'dd.mm.yyyy hh:mm')
                    : null), $row)));
            }
        }

        $writer->close();

        return $path;
    }

    private function patchSheet(string $path, callable $patch): void
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', $patch((string) $zip->getFromName('xl/worksheets/sheet1.xml')));
        $zip->close();
    }
}
