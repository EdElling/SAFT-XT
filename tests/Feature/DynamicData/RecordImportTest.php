<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataAccess;
use App\DynamicData\DataPermissions;
use App\DynamicData\Import\RecordImporter;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Activitylog\Models\Activity;

/** Datenimport in eine bestehende Tabelle: nur neue Datensätze, Prüfung wie beim Erfassen, alles oder nichts. */
class RecordImportTest extends DynamicDataTestCase
{
    private CustomTable $orders;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());

        $this->service()->createTable(['name' => 'kunden', 'label' => ['de' => 'Kunden', 'en' => 'Customers']], [
            ['name' => 'name', 'label' => ['de' => 'Name', 'en' => 'Name'], 'type' => 'string', 'is_required' => true, 'is_unique' => true],
        ]);
        $this->orders = $this->service()->createTable(['name' => 'bestellungen', 'label' => ['de' => 'Bestellungen', 'en' => 'Orders'], 'import_enabled' => true], [
            ['name' => 'nummer', 'label' => ['de' => 'Nummer', 'en' => 'Number'], 'type' => 'string', 'is_required' => true, 'is_unique' => true],
            ['name' => 'kunde_id', 'label' => ['de' => 'Kunde', 'en' => 'Customer'], 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['name']]],
            ['name' => 'status', 'label' => ['de' => 'Status', 'en' => 'Status'], 'type' => 'select', 'options' => ['options' => [['value' => 'neu', 'label' => ['de' => 'Neu', 'en' => 'New']], ['value' => 'versandt', 'label' => ['de' => 'Versandt', 'en' => 'Shipped']]]]],
            ['name' => 'datum', 'label' => ['de' => 'Datum', 'en' => 'Date'], 'type' => 'date'],
            ['name' => 'betrag', 'label' => ['de' => 'Betrag', 'en' => 'Amount'], 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2], 'validation_rules' => ['min' => 0]],
            ['name' => 'express', 'label' => ['de' => 'Express', 'en' => 'Express'], 'type' => 'boolean'],
            ['name' => 'brutto', 'label' => ['de' => 'Brutto', 'en' => 'Gross'], 'type' => 'computed', 'options' => ['formula' => 'round(betrag * 1.081, 2)', 'result' => 'decimal', 'precision' => 12, 'scale' => 2]],
        ]);

        foreach (['ACME AG', 'Beta GmbH'] as $name) {
            DynamicRecord::forTableName('kunden')->create(['name' => $name]);
        }
    }

    private function importer(): RecordImporter
    {
        return app(RecordImporter::class);
    }

    /** @param  list<list<mixed>>  $rows */
    private function xlsx(array $rows): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    private function csv(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_template_lists_the_importable_fields_and_marks_required_ones(): void
    {
        $path = $this->importer()->template($this->orders);
        $reader = new Reader;
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $this->assertSame(['Nummer *', 'Kunde', 'Status', 'Datum', 'Betrag', 'Express'], array_map(fn ($c) => $c->getValue(), $row->getCells()), 'Ohne berechnetes Feld Brutto.');

                break 2;
            }
        }

        $reader->close();
        unlink($path);
    }

    public function test_excel_file_creates_new_records_with_one_log_entry(): void
    {
        $path = $this->xlsx([
            ['Nummer *', 'Kunde', 'Status', 'Datum', 'Betrag', 'Express', 'Brutto'],
            ['B-1', 'ACME AG', 'Versandt', new \DateTimeImmutable('2026-02-01'), 100.5, 'Ja', 999],
            ['B-2', 'Beta GmbH', 'neu', '15.03.2026', 20, 'Nein', null],
            [null, null, null, null, null, null, null],
        ]);

        $analysis = $this->importer()->analyze($this->orders, $path, 'bestellungen.xlsx');
        $this->assertTrue($analysis->isValid(), implode(' ', $analysis->messages));
        $this->assertSame(['Brutto'], $analysis->ignored, 'Berechnetes Feld aus einem Export wird übergangen.');
        $this->assertCount(2, $analysis->rows, 'Leerzeile übersprungen.');

        $before = Activity::query()->count();
        $this->assertSame(2, $this->importer()->import($this->orders, $path, 'bestellungen.xlsx', auth()->user()));

        $first = DynamicRecord::forTableName('bestellungen')->newQuery()->where('nummer', 'B-1')->sole();
        $acme = DynamicRecord::forTableName('kunden')->newQuery()->where('name', 'ACME AG')->value('id');
        $this->assertSame([$acme, 'versandt', '2026-02-01', '100.50', true, '108.64'], [(int) $first->kunde_id, $first->status, substr((string) $first->datum, 0, 10), number_format((float) $first->betrag, 2, '.', ''), (bool) $first->express, number_format((float) $first->brutto, 2, '.', '')]);
        $this->assertSame(auth()->id(), (int) $first->created_by);

        // Ein Eintrag „importiert“ statt eines je Datensatz.
        $this->assertSame($before + 1, Activity::query()->count());
        $log = Activity::query()->latest('id')->first();
        $this->assertSame('imported', $log->event);
        $this->assertSame(2, $log->attribute_changes['attributes']['datensaetze']);
        unlink($path);
    }

    public function test_csv_with_english_headers_and_technical_names(): void
    {
        app()->setLocale('en');
        $path = $this->csv("Number,kunde_id,Status,date,Amount,Express\nB-7,Beta GmbH,Shipped,01/02/2026,\"1,234.50\",Yes\n");

        $this->assertSame(1, $this->importer()->import($this->orders, $path, 'orders.csv', auth()->user()));
        $record = DynamicRecord::forTableName('bestellungen')->newQuery()->where('nummer', 'B-7')->sole();
        $this->assertSame(['versandt', '2026-02-01', '1234.50'], [$record->status, substr((string) $record->datum, 0, 10), number_format((float) $record->betrag, 2, '.', '')]);
        unlink($path);
    }

    public function test_errors_name_row_and_column_and_nothing_is_imported(): void
    {
        DynamicRecord::forTableName('bestellungen')->create(['nummer' => 'B-1']);
        $path = $this->csv("Nummer;Kunde;Betrag;Datum;Express\nB-1;ACME AG;10;01.01.2026;Ja\nB-2;Unbekannt AG;abc;31.02.2026;vielleicht\nB-3;ACME AG;-5;;\nB-3;ACME AG;5;;\n;ACME AG;5;;\n");

        $analysis = $this->importer()->analyze($this->orders, $path, 'fehler.csv');
        $this->assertFalse($analysis->isValid());
        $text = implode("\n", $analysis->messages);

        foreach ([
            'Zeile 2, Spalte „Nummer“: „B-1“ ist bereits vergeben.',
            'Zeile 3, Spalte „Kunde“: „Unbekannt AG“ wurde nicht gefunden.',
            'Zeile 3, Spalte „Betrag“: „abc“ ist keine Zahl.',
            'Zeile 3, Spalte „Datum“: „31.02.2026“ ist kein gültiges Datum',
            'Zeile 3, Spalte „Express“: „vielleicht“ ist weder Ja noch Nein.',
            'Zeile 4, Spalte „Betrag“: Kleiner als das Minimum 0.',
            'Zeile 5, Spalte „Nummer“: „B-3“ kommt in der Datei mehrfach vor',
            'Zeile 6, Spalte „Nummer“: Pflichtfeld ist leer.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        try {
            $this->importer()->import($this->orders, $path, 'fehler.csv', auth()->user());
            $this->fail('Ungültige Datei erwartet.');
        } catch (BusinessRuleException) {
        }

        $this->assertSame(1, DynamicRecord::forTableName('bestellungen')->newQuery()->count(), 'Alles oder nichts.');

        // Fehlende Pflichtspalte.
        $missing = $this->csv("Kunde;Betrag\nACME AG;5\n");
        $this->assertSame(['Nummer'], $this->importer()->analyze($this->orders, $missing, 'x.csv')->missingRequired);
        unlink($path);
        unlink($missing);
    }

    public function test_dialog_checks_the_file_then_imports_after_confirmation(): void
    {
        $upload = UploadedFile::fake()->createWithContent('bestellungen.csv', "Nummer *;Kunde;Betrag;Brutto\nB-20;ACME AG;12,50;1\nB-21;Beta GmbH;7;2\n");

        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->assertActionVisible(TestAction::make('import_file')->table())
            ->mountAction(TestAction::make('import_file')->table())
            ->fillForm(['file' => $upload])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertNotNotified('Bitte eine Datei wählen.')
            ->assertActionDataSet(['summary' => ['rows' => 2, 'columns' => ['Nummer', 'Kunde', 'Betrag'], 'ignored' => ['Brutto']]])
            ->callMountedAction()
            ->assertNotified('2 Datensätze importiert.');

        $this->assertSame(['B-20', 'B-21'], DynamicRecord::forTableName('bestellungen')->newQuery()->orderBy('nummer')->pluck('nummer')->all());

        // Fehlerhafte Datei: Liste der Fehler, der Dialog bleibt im ersten Schritt, nichts angelegt.
        $bad = UploadedFile::fake()->createWithContent('fehler.csv', "Nummer;Betrag\nB-20;abc\n");
        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->mountAction(TestAction::make('import_file')->table())
            ->fillForm(['file' => $bad])
            ->goToNextWizardStep()
            ->assertNotified('Die Datei kann so nicht importiert werden – es wurde nichts angelegt');
        $this->assertSame(2, DynamicRecord::forTableName('bestellungen')->newQuery()->count());
    }

    public function test_import_needs_switch_create_and_import_permission(): void
    {
        $user = $this->userWithPermissions([DataPermissions::name('bestellungen', 'view'), DataPermissions::name('bestellungen', 'create')]);
        $this->actingAs($user);
        $this->assertFalse(DataAccess::canImport($this->orders), 'Ohne Recht „importieren“.');

        $user->givePermissionTo(DataPermissions::name('bestellungen', 'import'));
        $this->actingAs($user->fresh());
        $this->assertTrue(DataAccess::canImport($this->orders->fresh()));

        $this->orders->update(['import_enabled' => false]);
        $this->assertFalse(DataAccess::canImport($this->orders->fresh()), 'Ohne Freigabe in den Stammdaten.');
    }

    public function test_required_file_fields_stay_empty_and_the_dialog_says_so(): void
    {
        $belege = $this->service()->addField($this->orders, ['name' => 'beleg', 'label' => ['de' => 'Beleg', 'en' => 'Receipt'], 'type' => 'file', 'is_required' => true]);
        $this->orders->refresh();

        $this->assertStringNotContainsString('Beleg', implode(',', $this->importer()->analyze($this->orders, $this->csv("Nummer\nB-9\n"), 'a.csv')->columns));
        $path = $this->csv("Nummer;Beleg\nB-9;scan.png\n");
        $analysis = $this->importer()->analyze($this->orders, $path, 'a.csv');
        $this->assertTrue($analysis->isValid());
        $this->assertContains('Beleg', $analysis->ignored);

        $this->assertSame(1, $this->importer()->import($this->orders, $path, 'a.csv', auth()->user()));
        $this->assertNull(DynamicRecord::forTableName('bestellungen')->newQuery()->where('nummer', 'B-9')->sole()->beleg);
        unlink($path);

        $this->assertSame('Dateifelder (Beleg) werden nicht importiert und bleiben leer.', __('data.import.files_skipped', ['fields' => $belege->label]));
    }
}
