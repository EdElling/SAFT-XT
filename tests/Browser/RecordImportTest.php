<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Services\DynamicSchemaService;
use App\Work\Types\RecordImportWork;
use Illuminate\Support\Facades\File;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\CreatesOrderData;
use Tests\DuskTestCase;

/** Datenimport in eine bestehende Tabelle (Bereich IMP, B-116): Assistent mit Prüfung, Import als Arbeit (Fundament 2). */
class RecordImportTest extends DuskTestCase
{
    use CreatesOrderData;

    private const string ADMIN = 'admin@example.com';

    private const string FILE_INPUT = '.filepond--root input[type="file"]';

    private const string UPLOADED = '.filepond--item[data-filepond-item-state="processing-complete"]';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin(self::ADMIN);
        $this->createOrderData();
        app(DynamicSchemaService::class)->updateTable($this->kunden, ['import_enabled' => true]);
        $this->directory = sys_get_temp_dir().'/saft-record-import-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function csv(string $name, string $content): string
    {
        file_put_contents($this->directory.'/'.$name, $content);

        return $this->directory.'/'.$name;
    }

    /** Dialog öffnen, Datei anhängen, zur Prüfung. */
    private function upload(Browser $browser, string $path): Browser
    {
        $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('Beta AG', 15);
        $browser->clickAtXPath("//button[contains(., 'Importieren')]")->pause(300);
        $this->clickVisibleButton($browser, 'Datei importieren …');
        $browser->waitForText('Daten importieren: Kunden', 15)
            ->waitUntil('document.querySelector(\'.filepond--root input[type="file"]\') !== null', 15)->pause(300)
            ->attach(self::FILE_INPUT, $path)
            ->waitFor(self::UPLOADED, 30);

        return $this->clickVisibleButton($browser, 'Weiter');
    }

    /** IMP-09 */
    public function test_a_clean_file_is_checked_and_imported_after_confirmation(): void
    {
        $path = $this->csv('kunden.csv', "Firma;Ort\nGamma KG;Köln\nDelta SE;Bonn\n");

        $this->browse(function (Browser $browser) use ($path): void {
            $this->upload($browser, $path)
                ->waitForText('Die Datei ist in Ordnung: 2 neue Datensätze werden angelegt.', 30)
                ->assertSee('Erkannte Spalten: Firma, Ort');

            // Import als Arbeit: klein genug für die Mitarbeit im Request, Meldung wie gewohnt.
            $this->clickVisibleButton($browser, 'Importieren')
                ->waitForText('2 Datensätze importiert.', 30)
                ->waitForText('Gamma KG', 15)
                ->assertSee('Delta SE');
        });

        $this->assertSame(4, DynamicRecord::forTable($this->kunden)->newQuery()->count());
        $this->assertSame(WorkItem::DONE, WorkItem::query()->where('type', RecordImportWork::KEY)->sole()->status);
    }

    /** IMP-10 */
    public function test_a_faulty_file_is_rejected_with_row_and_column_and_nothing_is_created(): void
    {
        $path = $this->csv('fehler.csv', "Firma;Ort\n;Köln\nACME GmbH;Berlin\n");

        $this->browse(function (Browser $browser) use ($path): void {
            $this->upload($browser, $path)
                ->waitForText('Die Datei kann so nicht importiert werden – es wurde nichts angelegt', 30)
                ->assertSee('Zeile 2, Spalte „Firma“: Pflichtfeld ist leer.');
        });

        $this->assertSame(2, DynamicRecord::forTable($this->kunden)->newQuery()->count(), 'Alles oder nichts.');
        $this->assertSame(0, WorkItem::query()->where('type', RecordImportWork::KEY)->count(), 'Die Prüfung im Dialog braucht keine Arbeit.');
    }
}
