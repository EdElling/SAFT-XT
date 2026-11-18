<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Services\DynamicSchemaService;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Feldtyp „Datei“ (Bereich EIN, B-021). */
class FileFieldTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string TABLE = 'belegtest';

    private const string FILE_INPUT = '.fi-modal-window .filepond--root input[type="file"]';

    private const string UPLOADED = '.filepond--item[data-filepond-item-state="processing-complete"]';

    private const string INVALID = '.filepond--item[data-filepond-item-state="load-invalid"]';

    private CustomTable $table;

    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $this->table = app(DynamicSchemaService::class)->createTable(['name' => self::TABLE, 'label' => 'Belegtest'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'belege', 'label' => 'Belege', 'type' => 'file', 'options' => ['kinds' => ['images', 'pdf'], 'max_mb' => 2, 'max_files' => 3]],
        ]);
        app(DataMenu::class)->ensure($this->table);
    }

    protected function tearDown(): void
    {
        // Abgelegte Dateien des Testlaufs entfernen (Server und Test teilen sich den Speicher).
        Storage::disk((string) config('dynamic_data.files.disk', 'local'))->deleteDirectory('files/'.self::TABLE);

        foreach ($this->fixtures as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function fixture(string $name, string $content): string
    {
        $directory = storage_path('framework/testing/dusk-files');
        @mkdir($directory, 0775, true);
        $path = $directory.'/'.$name;
        file_put_contents($path, $content);

        return $this->fixtures[] = $path;
    }

    /** Echtes PNG ohne GD. */
    private static function png(int $width, int $height): string
    {
        $row = "\x00".str_repeat("\x20\x80\xc0", $width);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');
    }

    private function attach(Browser $browser, string $path, string $state = self::UPLOADED, int $count = 1): Browser
    {
        return $browser->attach(self::FILE_INPUT, $path)
            ->waitUntil("document.querySelectorAll('{$state}').length >= {$count}", 30);
    }

    /** EIN-14 */
    public function test_files_are_uploaded_listed_shown_and_served(): void
    {
        $image = $this->fixture('foto.png', self::png(40, 20));
        $pdf = $this->fixture('vertrag.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $text = $this->fixture('notiz.txt', 'nur Text');

        $this->browse(function (Browser $browser) use ($image, $pdf, $text): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/'.self::TABLE)->waitForText('Neuer Datensatz', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')
                ->waitFor('[id="mountedActionSchema0.titel"]', 15)
                ->type('[id="mountedActionSchema0.titel"]', 'Mietvertrag')
                ->waitUntil('document.querySelector(\''.self::FILE_INPUT.'\') !== null', 15)->pause(300);

            // Nicht erlaubte Art wird schon im Browser abgewiesen.
            $this->attach($browser, $text, self::INVALID);
            $browser->click('.filepond--item[data-filepond-item-state="load-invalid"] .filepond--action-remove-item')->pause(500);

            $this->attach($browser, $image);
            // Der Knopf enthält bei Datei-Feldern zusätzlich den (ausgeblendeten) Hinweis „Datei wird hochgeladen…“.
            $this->attach($browser, $pdf, count: 2)
                ->clickAtXPath("//div[contains(@class, 'fi-modal-footer')]//button[contains(., 'Erstellen') and not(contains(., 'weiterer'))]")
                ->waitForText('Mietvertrag', 15);

            // Liste: Vorschaubild und Link mit Namen.
            $browser->waitFor('tr.fi-ta-row img.saft-file-thumb', 15)
                ->assertSeeIn('tr.fi-ta-row', 'vertrag.pdf');

            $files = RecordFile::query()->orderBy('name')->get();
            $this->assertSame(['foto.png', 'vertrag.pdf'], $files->pluck('name')->all());
            $record = DynamicRecord::forTableName(self::TABLE)->newQuery()->where('titel', 'Mietvertrag')->sole();
            $this->assertSame([(string) $record->getKey()], $files->pluck('record_id')->unique()->values()->all());

            // Bild wird tatsächlich geladen (Zugriff über die geschützte Route).
            $browser->waitUntil("Array.from(document.querySelectorAll('img.saft-file-thumb')).every(i => i.complete && i.naturalWidth > 0)", 15);

            // Details: beide Dateien.
            $this->clickRow($browser, 'Mietvertrag')
                ->waitFor('[data-field-blocks] .saft-file-list', 15)
                ->assertSeeIn('[data-field-blocks]', 'vertrag.pdf')
                ->assertPresent('[data-field-blocks] img.saft-file-thumb');

            // Ohne Anmeldung keine Datei.
            $browser->driver->manage()->deleteAllCookies();
            $browser->visit(route('files.show', ['file' => $files->first()->id], false))
                ->assertPathIsNot('/files/'.$files->first()->id);
        });
    }
}
