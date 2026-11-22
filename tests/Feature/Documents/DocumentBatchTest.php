<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\DocumentBatch;
use App\Documents\DocumentGenerator;
use App\Documents\PdfRenderer;
use App\DynamicData\DataPermissions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\GitarrenshopDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;
use ZipArchive;

/** Dokumente für mehrere Datensätze (B-124, D3a): ein PDF oder ZIP, Ablage je Datensatz, Obergrenze, Sammelaktion. */
class DocumentBatchTest extends DynamicDataTestCase
{
    private CustomTable $receipts;

    private DocumentTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->receipts = CustomTable::query()->where('name', 'gitarren_belege')->sole();
        $this->template = DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->sole();
    }

    private function clerk(array $actions): User
    {
        $role = Role::query()->create(['name' => 'kasse-'.uniqid(), 'guard_name' => 'web', 'app_id' => $this->receipts->app_id]);
        $role->givePermissionTo([DocumentGenerator::PERMISSION, ...array_map(fn (string $a): string => DataPermissions::name('gitarren_belege', $a), $actions)]);
        $user = $this->userWithPermissions();
        $user->assignRole($role);

        return $user->fresh();
    }

    /** @return Collection<int, DynamicRecord> */
    private function receipts(int $count): Collection
    {
        return DynamicRecord::forTableName('gitarren_belege')->newQuery()->whereNotNull('kunde_id')->orderBy('id')->limit($count)->get();
    }

    private static function pages(string $pdf): int
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);

        try {
            return PdfRenderer::pageCount($file);
        } finally {
            unlink($file);
        }
    }

    public function test_one_pdf_with_all_documents_stored_per_record(): void
    {
        $user = $this->clerk(['view', 'update']);
        $this->actingAs($user);
        $records = $this->receipts(3);

        $result = app(DocumentBatch::class)->run($this->template, $records, $user, true);

        $this->assertSame([3, 3, [], 'application/pdf'], [$result['generated'], $result['stored'], $result['notes'], $result['mime']]);
        $this->assertSame('Rechnung-'.now()->format('Y-m-d').'.pdf', $result['file_name']);
        $this->assertStringStartsWith('%PDF-', $result['content']);
        $this->assertGreaterThanOrEqual(3, self::pages($result['content']), 'Je Dokument mindestens eine Seite.');
        $this->assertSame(3, RecordFile::query()->whereNotNull('sha256')->count());
        $this->assertSame(3, Activity::query()->where('event', DocumentGenerator::EVENT)->count(), 'Je Datensatz im Verlauf.');
    }

    public function test_zip_with_one_file_per_document_and_notes_when_storing_is_not_allowed(): void
    {
        $reader = $this->clerk(['view']);
        $this->actingAs($reader);

        $result = app(DocumentBatch::class)->run($this->template, $this->receipts(2), $reader, true, DocumentBatch::ZIP);

        $this->assertSame([2, 0, 'application/zip'], [$result['generated'], $result['stored'], $result['mime']]);
        $this->assertCount(2, $result['notes']);
        $this->assertStringContainsString('nicht abgelegt', $result['notes'][0]);

        $file = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($file, $result['content']);
        $zip = new ZipArchive;
        $zip->open($file);
        $names = array_map(static fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        unlink($file);

        $this->assertCount(2, array_unique($names));
        $this->assertStringStartsWith('Rechnung-', $names[0]);
        $this->assertSame(0, RecordFile::query()->whereNotNull('sha256')->count());
    }

    public function test_limit_is_checked_before_anything_is_created(): void
    {
        config(['documents.batch_max' => 2]);
        $user = $this->clerk(['view', 'update']);

        try {
            app(DocumentBatch::class)->run($this->template, $this->receipts(3), $user, true);
            $this->fail('Zu viele Datensätze.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('documents.batch_too_many', $exception->errorCode);
        }

        $this->assertSame(0, Activity::query()->where('event', DocumentGenerator::EVENT)->count());
    }

    public function test_bulk_action_in_the_data_table(): void
    {
        $this->actingAs($this->clerk(['view', 'update']));
        $records = $this->receipts(2);

        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->selectTableRecords($records->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all())
            ->assertActionVisible(TestAction::make('documentBulk')->table()->bulk())
            ->callAction(TestAction::make('documentBulk')->table()->bulk(), ['format' => DocumentBatch::PDF, 'store' => true])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('Rechnung-'.now()->format('Y-m-d').'.pdf')
            ->assertNotified('2 Dokumente erstellt, 2 abgelegt');

        // Ohne Vorlage keine Sammelaktion (und damit keine Häkchen).
        $this->actingAs($this->superAdmin());
        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_artikel'])
            ->assertActionHidden(TestAction::make('documentBulk')->table()->bulk());
    }
}
