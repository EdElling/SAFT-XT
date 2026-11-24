<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Apps\Definition\AppDefinition;
use App\Apps\Update\ChangePlanner;
use App\Documents\DocumentBatch;
use App\Documents\DocumentGenerator;
use App\Documents\DocxRenderer;
use App\Documents\LetterheadResolver;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Models\Activity;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\WorkItem;
use App\Work\Types\DocumentsWork;
use App\Work\WorkService;
use Database\Seeders\GitarrenshopDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;
use ZipArchive;

/** Word-Fassung (B-124, D4b): aus derselben Vorlage, Formate je Vorlage, nur herunterladen, Dialog und Sammelaktion. */
class DocxTest extends DynamicDataTestCase
{
    private CustomTable $receipts;

    private DocumentTemplate $template;

    private DynamicRecord $receipt;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->receipts = CustomTable::query()->where('name', 'gitarren_belege')->sole();
        $this->template = DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->sole();
        $this->receipt = DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'V-2025-001')->sole();
    }

    /** Text von document.xml, Kopf und Fusszeilen einer DOCX-Datei. */
    private static function xml(string $docx, string $part = 'word/document.xml'): string
    {
        $file = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($file, $docx);
        $zip = new ZipArchive;
        $zip->open($file);

        try {
            if ($part !== 'word/document.xml') {
                $all = '';

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    $all .= str_starts_with($name, $part) ? (string) $zip->getFromName($name) : '';
                }

                return $all;
            }

            return (string) $zip->getFromName($part);
        } finally {
            $zip->close();
            unlink($file);
        }
    }

    public function test_word_version_has_address_content_header_and_page_numbers(): void
    {
        $result = app(DocumentGenerator::class)->generate($this->template, $this->receipt, $this->superAdmin(), false, DocumentTemplate::FORMAT_DOCX);
        $document = $result['document'];
        $xml = self::xml($document->content());

        $this->assertSame('Rechnung-V-2025-001.docx', $document->fileName);
        $this->assertStringStartsWith('PK', $document->content());
        $this->assertSame('', $document->pdf);
        $this->assertStringContainsString('Rechnung V-2025-001', $xml);
        $this->assertStringContainsString('Lena Meier', $xml, 'Adressfeld.');
        $this->assertStringContainsString('<w:tblHeader', $xml, 'Kopf der Positionen auf jeder Seite.');
        $this->assertStringContainsString('Gitarrenshop Muster AG', self::xml($document->content(), 'word/header'), 'Absender im Kopf.');
        $this->assertStringContainsString('NUMPAGES', self::xml($document->content(), 'word/footer'), 'Seitenzahlen.');
        $this->assertStringNotContainsString('Empfangsschein', $xml, 'Keine QR-Rechnung in Word.');
    }

    public function test_template_classes_become_word_formats(): void
    {
        $letterhead = app(LetterheadResolver::class)->for($this->template->app_id);
        $html = app(DocxRenderer::class)->inline('<table class="positionen"><thead><tr><th class="rechts">Netto</th></tr></thead>'
            .'<tbody><tr><td>A<br><span class="sekundaer klein">B</span></td></tr><tr class="summe"><td class="rechts fett">9</td></tr></tbody></table>', $letterhead);

        $this->assertStringContainsString('background-color: '.$letterhead->primary, $html, 'Kopf in der Primärfarbe.');
        $this->assertStringContainsString('text-align: right', $html);
        $this->assertStringContainsString('color: '.$letterhead->secondary.'; font-size: 8pt', $html, 'Mehrere Klassen zusammen.');
        $this->assertStringContainsString('border-top: 1.5pt '.$letterhead->text.' solid', $html, 'Summenzeile.');
        $this->assertStringContainsString('<br/>', $html, 'XHTML für PhpWord.');
        $this->assertStringNotContainsString('class=', $html);
    }

    public function test_word_is_never_stored_and_logged_with_its_format(): void
    {
        $user = $this->superAdmin();
        $result = app(DocumentGenerator::class)->generate($this->template, $this->receipt, $user, true, DocumentTemplate::FORMAT_DOCX);

        $this->assertNull($result['file']);
        $this->assertSame(0, RecordFile::query()->whereNotNull('sha256')->count());
        $this->assertSame('DOCX', Activity::query()->where('event', DocumentGenerator::EVENT)->sole()->attribute_changes['attributes']['format']);
        $this->assertSame('Rechnung (2).docx', DocumentGenerator::versionedName('Rechnung.docx', ['Rechnung.docx']));

        // Eine Vorlage nur für PDF erzeugt kein Word.
        $this->template->update(['formats' => DocumentTemplate::FORMAT_PDF]);
        $this->expectException(BusinessRuleException::class);
        app(DocumentGenerator::class)->generate($this->template->fresh(), $this->receipt, $user, false, DocumentTemplate::FORMAT_DOCX);
    }

    public function test_dialog_offers_word_without_storing_or_sending(): void
    {
        $this->actingAs($this->superAdmin());
        $action = TestAction::make('documentRow')->table($this->receipt);

        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->mountAction($action)
            ->assertActionDataSet(['format' => DocumentTemplate::FORMAT_PDF])
            ->assertFormFieldVisible('store')
            ->setActionData(['format' => DocumentTemplate::FORMAT_DOCX])
            ->assertFormFieldHidden('store')
            ->assertFormFieldHidden('send')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('Rechnung-V-2025-001.docx');

        $this->assertSame(0, RecordFile::query()->whereNotNull('sha256')->count());

        // Nur PDF: keine Wahl.
        $this->template->update(['formats' => DocumentTemplate::FORMAT_PDF]);
        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->mountAction($action)
            ->assertFormFieldHidden('format');
    }

    public function test_bulk_word_documents_come_as_zip(): void
    {
        $user = $this->superAdmin();
        $records = DynamicRecord::forTableName('gitarren_belege')->newQuery()->whereNotNull('kunde_id')->orderBy('id')->limit(2)->get();

        // Word kommt immer als ZIP; abgelegt oder versendet wird nie, auch wenn beides gewünscht ist.
        $this->actingAs($user);
        $item = app(WorkService::class)->startAndDrive(DocumentsWork::KEY, [
            'template_id' => $this->template->id, 'record_ids' => $records->modelKeys(), 'store' => true, 'format' => DocumentBatch::ZIP,
            'send' => true, 'copy' => false, 'document' => DocumentTemplate::FORMAT_DOCX, 'file' => DocumentBatch::fileName($this->template, DocumentBatch::ZIP),
        ], $user);

        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertStringEndsWith('.zip', (string) $item->resultFile());
        $this->assertSame([0, 0], [$item->result['stored'], $item->result['sent']]);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($item->directory().'/'.$item->resultFile()));
        $names = array_map(static fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();

        $this->assertCount(2, $names);
        $this->assertSame(['docx', 'docx'], array_map(static fn (string $n): string => pathinfo($n, PATHINFO_EXTENSION), $names));
    }

    public function test_app_definition_carries_formats_and_older_definitions_mean_pdf(): void
    {
        $app = App::query()->findOrFail($this->receipts->app_id);
        $definition = app(AppDefinition::class)->build($app, AppDefinition::PARTS, $this->superAdmin());

        $this->assertSame('both', $definition['documents'][0]['formats']);

        // Definition vor D4b (ohne „formats“) bei einer Vorlage mit PDF: keine Änderung geplant.
        $this->template->update(['formats' => DocumentTemplate::FORMAT_PDF]);
        $older = app(AppDefinition::class)->build($app, AppDefinition::PARTS, $this->superAdmin());
        unset($older['documents'][0]['formats']);
        $steps = app(ChangePlanner::class)->plan($app->fresh(), $older, $this->superAdmin())->steps;

        $this->assertSame([], array_values(array_filter($steps, static fn ($step): bool => $step->kind === 'document')));
    }
}
