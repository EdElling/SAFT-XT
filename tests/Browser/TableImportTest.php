<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DynamicDataNamespace;
use App\Models\CustomTable;
use Illuminate\Support\Facades\File;
use Laravel\Dusk\Browser;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\DuskTestCase;

/** Tabelle aus CSV-/Excel-Datei importieren (Bereich IMP). */
class TableImportTest extends DuskTestCase
{
    private const string FILE_INPUT = '.filepond--root input[type="file"]';

    private const string ADMIN = 'admin@example.com';

    private const string UPLOADED = '.filepond--item[data-filepond-item-state="processing-complete"]';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin();
        $this->file = sys_get_temp_dir().'/saft-import-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->file);

        parent::tearDown();
    }

    /** IMP-01 */
    public function test_import_creates_table_menu_entry_and_data(): void
    {
        $path = $this->csv('Lieferanten.csv', "Nr;Firma;PLZ;Umsatz;Seit\n1;Alpha GmbH;01067;1.234,50;01.03.2020\n2;Beta AG;80331;99;15.11.2021\n");

        $this->browse(function (Browser $browser) use ($path): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-designer')->waitForText('Importieren', 15)
                ->clickLink('Importieren')
                ->waitForText('Tabelle aus Datei importieren', 15)
                // FilePond baut sein Dateifeld erst nach dem Dialog auf (und ersetzt es dabei).
                ->waitUntil('document.querySelector(\'.filepond--root input[type="file"]\') !== null', 15)->pause(300)
                ->attach(self::FILE_INPUT, $path)
                ->waitFor(self::UPLOADED, 30);

            $this->clickVisibleButton($browser, 'Weiter')
                ->waitForText('2 Datenzeilen, 5 Spalten. Trennzeichen: Semikolon, Zeichensatz: UTF-8.', 30);
            $this->clickVisibleButton($browser, 'Weiter')
                ->waitForText('Text statt Zahl, weil Werte mit führender Null vorkommen', 15);
            $this->clickVisibleButton($browser, 'Weiter')
                ->waitForText('Bezeichnung der Tabelle', 15)
                ->assertInputValue('input[id="form.table_name"]', 'lieferanten')
                ->waitForText('Tabelle anlegen und Daten importieren', 15)
                ->pause(300);
            $browser->press('Tabelle anlegen und Daten importieren')
                ->waitForText('Tabelle „Lieferanten“ angelegt, 2 Datensätze importiert', 30)
                ->waitForText('Alpha GmbH', 15)
                ->assertSee('Beta AG')
                ->assertPathIs('/admin/data/lieferanten')
                ->assertSeeIn('.fi-sidebar', 'Lieferanten');
        });

        $namespace = app(DynamicDataNamespace::class);
        $this->assertSame(2, $namespace->connection()->table($namespace->qualify('lieferanten'))->count());
    }

    /** IMP-02 */
    public function test_untidy_file_shows_all_problems_and_creates_nothing(): void
    {
        $path = $this->csv('liste.csv', "Name;;Name\na;b;c\n\na;b;c\n");

        $this->browse(function (Browser $browser) use ($path): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-designer/import')
                ->waitForText('Tabelle aus Datei importieren', 15)
                // FilePond baut sein Dateifeld erst nach dem Dialog auf (und ersetzt es dabei).
                ->waitUntil('document.querySelector(\'.filepond--root input[type="file"]\') !== null', 15)->pause(300)
                ->attach(self::FILE_INPUT, $path)
                ->waitFor(self::UPLOADED, 30);

            $this->clickVisibleButton($browser, 'Weiter')
                ->waitForText('Die Datei kann so nicht importiert werden', 30)
                ->assertSee('Zeile 3 ist leer, danach folgen weitere Daten.')
                ->assertSee('Spalte B hat keinen Titel.')
                ->assertSee('Der Titel „Name“ kommt mehrfach vor (Spalten A, C).')
                ->assertDontSee('Datenzeilen');
        });

        $this->assertSame(0, CustomTable::query()->where('name', 'liste')->count());
    }

    /** IMP-04 (Blattauswahl) */
    public function test_sheet_can_be_chosen_right_after_upload(): void
    {
        @mkdir($this->file);
        $path = $this->file.'/Mappe.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Hinweise');
        $writer->addRow(Row::fromValues(['Bitte Blatt Artikel verwenden']));
        $writer->addNewSheetAndMakeItCurrent()->setName('Artikel');
        $writer->addRow(Row::fromValues(['Artikel', 'Preis']));
        $writer->addRow(Row::fromValues(['Tisch', 99.9]));
        $writer->close();

        $this->browse(function (Browser $browser) use ($path): void {
            $this->login($browser, self::ADMIN)->visit('/admin/table-designer/import')
                ->waitForText('Tabelle aus Datei importieren', 15)
                // FilePond baut sein Dateifeld erst nach dem Dialog auf (und ersetzt es dabei).
                ->waitUntil('document.querySelector(\'.filepond--root input[type="file"]\') !== null', 15)->pause(300)
                ->attach(self::FILE_INPUT, $path)
                ->waitFor(self::UPLOADED, 30)
                ->waitFor('select[id="form.sheet"]', 30)
                ->assertSee('Die Datei hat mehrere Blätter')
                ->select('select[id="form.sheet"]', 'Artikel')
                ->pause(1000);

            $this->clickVisibleButton($browser, 'Weiter')
                ->waitForText('1 Datenzeile, 2 Spalten. Blatt: Artikel.', 30);
        });
    }

    private function csv(string $name, string $content): string
    {
        // Eindeutiges Verzeichnis, der Dateiname selbst bleibt (er bestimmt den Tabellennamen).
        @mkdir($this->file);
        file_put_contents($path = $this->file.'/'.$name, $content);

        return $path;
    }
}
