<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataAccess;
use App\DynamicData\DataPermissions;
use App\DynamicData\Import\RecordImporter;
use App\Filament\Resources\WorkItems\Tables\WorkItemsTable;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Work\TimeLimit;
use App\Work\Types\RecordImportWork;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Activitylog\Models\Activity;

/**
 * Datenimport in eine bestehende Tabelle: nur neue Datensätze, Prüfung wie beim Erfassen, alles oder nichts – als Arbeit
 * in Schritten (Fundament 2, F3b): erst prüfen, dann einspielen, bei Fehler oder Abbruch zurücknehmen.
 */
class RecordImportTest extends DynamicDataTestCase
{
    private CustomTable $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    private function import(string $path, string $fileName): WorkItem
    {
        return $this->importRecords($this->orders, $path, $fileName);
    }

    private function orders(): \Illuminate\Database\Eloquent\Builder
    {
        return DynamicRecord::forTableName('bestellungen')->newQuery();
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
        $this->assertSame([2, true], [$analysis->rows, $analysis->checked], 'Leerzeile übersprungen.');

        $before = Activity::query()->where('log_name', 'data')->count();
        $item = $this->import($path, 'bestellungen.xlsx');
        $this->assertSame([WorkItem::DONE, '2 Datensätze importiert.', 2], [$item->status, $item->message, (int) $item->result['records']]);
        $this->assertSame(['Bestellungen · bestellungen.xlsx', 2, null], [$item->description(), $item->done, $item->total], 'Erledigt zählt die eingespielten Datensätze; die Menge kennt nur der Assistent.');

        $first = DynamicRecord::forTableName('bestellungen')->newQuery()->where('nummer', 'B-1')->sole();
        $acme = DynamicRecord::forTableName('kunden')->newQuery()->where('name', 'ACME AG')->value('id');
        $this->assertSame([$acme, 'versandt', '2026-02-01', '100.50', true, '108.64'], [(int) $first->kunde_id, $first->status, substr((string) $first->datum, 0, 10), number_format((float) $first->betrag, 2, '.', ''), (bool) $first->express, number_format((float) $first->brutto, 2, '.', '')]);
        $this->assertSame(auth()->id(), (int) $first->created_by);

        // Ein Eintrag „importiert“ statt eines je Datensatz.
        $this->assertSame($before + 1, Activity::query()->where('log_name', 'data')->count());
        $log = Activity::query()->where('log_name', 'data')->latest('id')->first();
        $this->assertSame('imported', $log->event);
        $this->assertSame(2, $log->attribute_changes['attributes']['datensaetze']);
        $this->assertSame([], Storage::disk('local')->files($item->directory()), 'Datei und Hilfsdateien sind weg.');
        unlink($path);
    }

    public function test_csv_with_english_headers_and_technical_names(): void
    {
        app()->setLocale('en');
        $path = $this->csv("Number,kunde_id,Status,date,Amount,Express\nB-7,Beta GmbH,Shipped,01/02/2026,\"1,234.50\",Yes\n");

        $this->assertSame(WorkItem::DONE, $this->import($path, 'orders.csv')->status);
        $record = $this->orders()->where('nummer', 'B-7')->sole();
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

        // Als Arbeit: die Prüfphase meldet dieselben Fehler, es wird nichts angelegt.
        $item = $this->import($path, 'fehler.csv');
        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringStartsWith('Die Datei enthält Fehler; es wurde nichts importiert.'."\n", (string) $item->message);
        $this->assertStringContainsString('Zeile 6, Spalte „Nummer“: Pflichtfeld ist leer.', (string) $item->message);
        $this->assertSame(1, $this->orders()->count(), 'Alles oder nichts.');

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
            ->assertActionDataSet(['summary' => ['rows' => 2, 'columns' => ['Nummer', 'Kunde', 'Betrag'], 'ignored' => ['Brutto'], 'checked' => true]])
            ->callMountedAction()
            ->assertNotified('2 Datensätze importiert.')
            ->assertNoRedirect();

        $this->assertSame(['B-20', 'B-21'], $this->orders()->orderBy('nummer')->pluck('nummer')->all());
        $this->assertSame(WorkItem::DONE, WorkItem::query()->where('type', RecordImportWork::KEY)->sole()->status, 'Kleine Datei: fertig im Request.');

        // Fehlerhafte Datei: Liste der Fehler, der Dialog bleibt im ersten Schritt, nichts angelegt.
        $bad = UploadedFile::fake()->createWithContent('fehler.csv', "Nummer;Betrag\nB-20;abc\n");
        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->mountAction(TestAction::make('import_file')->table())
            ->fillForm(['file' => $bad])
            ->goToNextWizardStep()
            ->assertNotified('Die Datei kann so nicht importiert werden – es wurde nichts angelegt');
        $this->assertSame(2, $this->orders()->count());
    }

    /** Grosse Dateien prüft der Assistent nicht vorab; die Arbeit prüft, spielt ein und meldet Fehler mit der Liste. */
    public function test_large_file_is_checked_by_the_work_and_the_dialog_redirects_to_it(): void
    {
        config(['dynamic_data.import.check_rows' => 1]);
        app()->instance(TimeLimit::class, new class extends TimeLimit
        {
            public function budget(float $wanted, int $margin = 5): float
            {
                return 0.0;
            }
        });
        $upload = UploadedFile::fake()->createWithContent('gross.csv', "Nummer;Kunde;Betrag\nB-30;ACME AG;1\nB-31;Beta GmbH;2\nB-32;ACME AG;3\n");

        $component = Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->mountAction(TestAction::make('import_file')->table())
            ->fillForm(['file' => $upload])
            ->goToNextWizardStep()
            ->assertActionDataSet(['summary' => ['rows' => 3, 'columns' => ['Nummer', 'Kunde', 'Betrag'], 'ignored' => [], 'checked' => false]])
            ->callMountedAction();
        $item = WorkItem::query()->where('type', RecordImportWork::KEY)->sole();
        $component->assertRedirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));

        // Budget 0: der erste Schritt hat nur geprüft (mindestens ein Block), die Datei liegt im Ordner der Arbeit.
        $this->assertSame([WorkItem::RUNNING, RecordImportWork::IMPORT, 3, 0], [$item->status, $item->state['phase'], $item->total, $item->done]);
        $this->assertSame('Einspielen: 0 von 3 Datensätzen (0 %)', WorkItemsTable::progress($item));
        $this->assertTrue(Storage::disk('local')->exists($item->directory().'/source.csv'));
        $this->assertSame(0, $this->orders()->count(), 'Noch nichts eingespielt.');

        // Der Scheduler übernimmt.
        $this->travel(1)->minutes();
        $this->artisan('work:run')->assertSuccessful();
        $item->refresh();
        $this->assertSame([WorkItem::DONE, 3, 3], [$item->status, $item->done, (int) $item->result['records']]);
        $this->assertSame("3 / 3 (100 %)", WorkItemsTable::progress($item), 'Fertig: die Zahlen wie üblich.');
        $this->assertSame(['B-30', 'B-31', 'B-32'], $this->orders()->orderBy('nummer')->pluck('nummer')->all());

        // Fehler, die erst die Arbeit findet: fehlgeschlagen mit der Liste, nichts angelegt; der Dialog zeigt sie.
        $bad = UploadedFile::fake()->createWithContent('fehler.csv', "Nummer;Betrag\nB-40;1\nB-41;abc\n");
        app()->forgetInstance(TimeLimit::class);
        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->mountAction(TestAction::make('import_file')->table())
            ->fillForm(['file' => $bad])
            ->goToNextWizardStep()
            ->callMountedAction()
            ->assertNotified('Import fehlgeschlagen – es wurde nichts angelegt');
        $failed = WorkItem::query()->where('type', RecordImportWork::KEY)->latest('id')->first();
        $this->assertSame(WorkItem::FAILED, $failed->status);
        $this->assertStringContainsString('Zeile 3, Spalte „Betrag“: „abc“ ist keine Zahl.', (string) $failed->message);
        $this->assertSame(3, $this->orders()->count());
    }

    /** Schritt für Schritt: prüfen blockweise mit Eindeutigkeit über die Blöcke, dann einspielen; Abbruch nimmt alles zurück. */
    public function test_import_runs_in_steps_and_cancelling_removes_what_was_created(): void
    {
        $runner = app(WorkRunner::class);
        $service = app(WorkService::class);
        $lines = ["Nummer;Kunde;Betrag"];

        for ($i = 1; $i <= RecordImportWork::CHUNK + 5; $i++) {
            $lines[] = "S-$i;ACME AG;$i";
        }

        $lines[] = 'S-3;ACME AG;0'; // Doppelt zu Zeile 4 – in einem anderen Block
        $path = $this->csv(implode("\n", $lines)."\n");
        $start = function () use ($service, &$lines, $path): WorkItem {
            return $service->start(RecordImportWork::KEY, ['table_id' => $this->orders->id, 'file' => 'serie.csv', 'source' => 'source.csv', 'rows' => count($lines) - 1], auth()->user(), files: ['source.csv' => $path]);
        };

        // Budget 0: je Aufruf ein Prüfblock (CHUNK Zeilen), die Werte eindeutiger Felder wandern über unique.json.
        $item = $runner->advance($start(), 0);
        $this->assertSame([RecordImportWork::CHECK, RecordImportWork::CHUNK, 0], [$item->state['phase'], $item->state['line'] - 1, $item->done], 'Prüfen zählt nicht als erledigt.');
        $this->assertSame('Prüfen: 200 von 206 Zeilen (0 %)', WorkItemsTable::progress($item));
        $this->assertTrue(Storage::disk('local')->exists($item->directory().'/unique.json'));
        $item = $runner->advance($item, 0);
        $this->assertSame(WorkItem::FAILED, $item->status, 'Das Duplikat im zweiten Block wird am Ende der Prüfung gemeldet.');
        $this->assertStringContainsString('Zeile '.(count($lines)).', Spalte „Nummer“: „S-3“ kommt in der Datei mehrfach vor', (string) $item->message);
        $this->assertSame(0, $this->orders()->count());
        $this->assertSame([], Storage::disk('local')->allFiles($item->directory()), 'Ordner der fehlgeschlagenen Arbeit ist weg.');

        // Ohne Duplikat: Prüfen in zwei Schritten, Einspielen je Schritt mindestens ein Datensatz, dann abbrechen.
        array_pop($lines);
        file_put_contents($path, implode("\n", $lines)."\n");
        $item = $runner->advance($start(), 0);
        $item = $runner->advance($item, 0);
        $this->assertSame([RecordImportWork::IMPORT, 0, 0], [$item->state['phase'], $item->state['line'], $item->done]);
        $item = $runner->advance($item, 0);
        $this->assertSame([WorkItem::RUNNING, 1, 2, 1], [$item->status, $item->state['created'], $item->state['line'], $item->done]);
        $this->assertSame('Einspielen: 1 von 205 Datensätzen (0 %)', WorkItemsTable::progress($item));
        $item = $runner->advance($item, 0);
        $this->assertSame(2, $this->orders()->count(), 'Je Schritt ein Datensatz, committet.');
        $this->assertSame(
            $this->orders()->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            array_values(array_filter(array_map('trim', (array) file(Storage::disk('local')->path($item->directory().'/created.ids'))))),
            'Die angelegten IDs sind für das Zurücknehmen festgehalten.',
        );

        $item = $service->cancel($item);
        $this->assertSame(WorkItem::CANCELLED, $item->status);
        $this->assertSame(0, $this->orders()->withTrashed()->count(), 'Abbruch nimmt die angelegten Datensätze endgültig zurück.');
        $this->assertSame(0, Activity::query()->where('log_name', 'data')->where('event', 'imported')->count());
        unlink($path);
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

        $this->assertSame(WorkItem::DONE, $this->import($path, 'a.csv')->status);
        $this->assertNull($this->orders()->where('nummer', 'B-9')->sole()->beleg);
        unlink($path);

        $this->assertSame('Dateifelder (Beleg) werden nicht importiert und bleiben leer.', __('data.import.files_skipped', ['fields' => $belege->label]));
    }
}
