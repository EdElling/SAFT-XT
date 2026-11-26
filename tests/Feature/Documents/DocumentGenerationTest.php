<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\DocumentGenerator;
use App\DynamicData\DataPermissions;
use App\DynamicData\RecordCopier;
use App\DynamicData\Ui\DynamicRecordActions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\GitarrenshopDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Dokument erzeugen und ablegen (B-124, D1c): Rechte, neue Fassung, Prüfwert, Protokoll, Schreibschutz, Kopieren. */
class DocumentGenerationTest extends DynamicDataTestCase
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

    /** Benutzer mit App-Rolle „Dokumente: erzeugen“ und den genannten Datenrechten auf die Belege. */
    private function clerk(array $actions = ['view']): User
    {
        $role = Role::query()->create(['name' => 'kasse-'.uniqid(), 'guard_name' => 'web', 'app_id' => $this->receipts->app_id]);
        $role->givePermissionTo(['documents.generate', ...array_map(fn (string $a): string => DataPermissions::name('gitarren_belege', $a), $actions)]);
        $user = $this->userWithPermissions();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_generates_stores_new_versions_and_logs_the_checksum(): void
    {
        $user = $this->clerk(['view', 'update']);
        $this->actingAs($user);
        $generator = app(DocumentGenerator::class);

        $first = $generator->generate($this->template, $this->receipt, $user, true);
        $second = $generator->generate($this->template, $this->receipt->fresh(), $user, true);

        $this->assertSame(['Rechnung-V-2025-001.pdf', 'Rechnung-V-2025-001 (2).pdf'], [$first['file']->name, $second['file']->name]);
        $this->assertSame(hash('sha256', $first['document']->pdf), $first['sha256']);
        $file = RecordFile::query()->findOrFail($first['file']->id);
        $this->assertSame([(string) $this->receipt->getKey(), $this->template->id, $first['sha256']], [$file->record_id, $file->document_template_id, $file->sha256]);
        $this->assertTrue($file->isGeneratedDocument());
        Storage::disk('local')->assertExists($file->path);
        $this->assertContains($file->id, array_column((array) $this->receipt->fresh()->dateien, 'id'));

        $log = Activity::query()->where('event', DocumentGenerator::EVENT)->latest('id')->firstOrFail();
        $this->assertSame([$user->id, 'Rechnung', 'Rechnung-V-2025-001 (2).pdf', $second['sha256'], 'Belegdateien'], [
            $log->causer_id, $log->attribute_changes['attributes']['vorlage'], $log->attribute_changes['attributes']['datei'], $log->attribute_changes['attributes']['sha256'], $log->attribute_changes['attributes']['abgelegt'],
        ]);
    }

    public function test_permissions_for_generating_and_storing(): void
    {
        $generator = app(DocumentGenerator::class);

        $this->assertTrue($generator->templatesFor($this->receipts, $this->userWithPermissions([DataPermissions::name('gitarren_belege', 'view')]))->isEmpty(), 'Ohne „Dokumente: erzeugen“.');
        $this->assertTrue($generator->templatesFor($this->receipts, $this->clerk([]))->isEmpty(), 'Ohne Leserecht.');

        $reader = $this->clerk(['view']);
        $this->assertSame([$this->template->id], $generator->templatesFor($this->receipts, $reader)->pluck('id')->all());
        $this->assertFalse($generator->canStore($this->template, $this->receipts, $this->receipt, $reader), 'Ablegen braucht das Bearbeiten-Recht.');
        $this->assertSame([], app(DocumentGenerator::class)->generate($this->template, $this->receipt, $reader, false)['file'] === null ? [] : ['abgelegt']);

        $this->expectException(BusinessRuleException::class);
        $generator->generate($this->template, $this->receipt, $reader, true);
    }

    public function test_action_only_on_tables_with_templates(): void
    {
        $this->actingAs($this->clerk(['view']));
        $this->assertTrue(DynamicRecordActions::document($this->receipts)->record($this->receipt)->isVisible());

        $articles = CustomTable::query()->where('name', 'gitarren_artikel')->sole();
        $this->assertFalse(DynamicRecordActions::document($articles)->isVisible(), 'Keine Vorlage für Artikel.');
    }

    public function test_generated_documents_need_the_delete_permission_and_stay_with_the_original(): void
    {
        $editor = $this->clerk(['view', 'update', 'create']);
        $this->actingAs($editor);
        $stored = app(DocumentGenerator::class)->generate($this->template, $this->receipt, $editor, true)['file'];
        $receipt = $this->receipt->fresh();

        // Kopieren: das Dokument bleibt beim Original.
        $copy = RecordCopier::values($this->receipts, $receipt);
        $result = app(RecordCopier::class)->copy($this->receipts, $receipt, $copy, [], []);
        $this->assertSame('V-2025-003', $result['record']->belegnummer, 'Die Kopie bekommt eine neue Belegnummer im Jahr ihres Belegdatums.');
        $this->assertNotContains($stored->name, array_column((array) $result['record']->dateien, 'name'));

        // Entfernen ohne Lösch-Recht: abgelehnt.
        $receipt->dateien = array_values(array_filter((array) $receipt->dateien, static fn (array $f): bool => $f['id'] !== $stored->id));

        try {
            $receipt->save();
            $this->fail('Entfernen ohne Lösch-Recht muss abgelehnt werden.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('Belegdateien', $exception->getMessage());
        }

        $this->actingAs($this->clerk(['view', 'update', 'delete']));
        $receipt->save();
        $this->assertNull(RecordFile::query()->find($stored->id), 'Mit Lösch-Recht entfernt (und gelöscht).');
    }

    public function test_versioned_names(): void
    {
        $this->assertSame('R-1.pdf', DocumentGenerator::versionedName('R-1.pdf', ['A.pdf']));
        $this->assertSame('R-1 (3).pdf', DocumentGenerator::versionedName('R-1.pdf', ['R-1.pdf', 'R-1 (2).pdf']));
    }

    public function test_row_action_downloads_and_stores_the_document(): void
    {
        $this->actingAs($this->clerk(['view', 'update']));

        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->assertActionVisible(TestAction::make('documentRow')->table($this->receipt))
            ->callAction(TestAction::make('documentRow')->table($this->receipt), ['store' => true])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('Rechnung-V-2025-001.pdf')
            ->assertNotified('Dokument „Rechnung-V-2025-001.pdf“ abgelegt');

        $file = RecordFile::query()->where('name', 'Rechnung-V-2025-001.pdf')->sole();
        $this->assertStringContainsString(RecordFile::DOCUMENT_MARKER.'=1', $file->url(), 'Markierung für den Schutz im Upload-Feld.');
    }

    public function test_full_file_field_only_downloads(): void
    {
        $user = $this->clerk(['view', 'update']);
        $this->actingAs($user);
        $this->receipt->dateien = array_map(static fn (int $i): array => ['id' => 'x'.$i, 'name' => 'beleg'.$i.'.pdf'], range(1, 5));
        DynamicRecord::withoutEvents(fn () => $this->receipt->save());

        try {
            app(DocumentGenerator::class)->generate($this->template, $this->receipt->fresh(), $user, true);
            $this->fail('Feld voll.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('documents.field_full', $exception->errorCode);
        }

        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->callAction(TestAction::make('documentRow')->table($this->receipt), ['store' => true])
            ->assertFileDownloaded('Rechnung-V-2025-001.pdf');
        $this->assertSame(0, RecordFile::query()->whereNotNull('sha256')->count());
    }

    public function test_template_offers_only_file_fields_for_pdf_as_storage(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(EditDocumentTemplate::class, ['record' => $this->template->getKey()])
            ->assertFormFieldExists('file_field')
            ->assertFormSet(['file_field' => 'dateien'])
            ->fillForm(['file_field' => 'gibtsnicht'])
            ->call('save')
            ->assertHasFormErrors(['file_field']);
    }
}
