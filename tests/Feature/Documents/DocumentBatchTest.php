<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\DocumentBatch;
use App\Documents\DocumentGenerator;
use App\Documents\PdfRenderer;
use App\DynamicData\DataPermissions;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\TimeLimit;
use App\Work\Types\DocumentsWork;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Database\Seeders\GitarrenshopDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;
use ZipArchive;

/**
 * Dokumente für mehrere Datensätze (B-124, D3a) als Arbeit (Fundament 2, F3b): ein PDF oder ZIP, Ablage je Datensatz,
 * Schritte, Obergrenze, Sammelaktion.
 */
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

    /**
     * Parameter der Arbeit wie aus der Sammelaktion.
     *
     * @param  Collection<int, DynamicRecord>  $records
     * @return array<string, mixed>
     */
    private function parameters(Collection $records, bool $store, string $format = DocumentBatch::PDF, bool $send = false): array
    {
        return [
            'template_id' => $this->template->id,
            'record_ids' => $records->modelKeys(),
            'store' => $store,
            'format' => $format,
            'send' => $send,
            'copy' => false,
            'document' => DocumentTemplate::FORMAT_PDF,
            'file' => DocumentBatch::fileName($this->template, $format),
        ];
    }

    private function resultFile(WorkItem $item): string
    {
        return (string) Storage::disk('local')->get($item->directory().'/'.$item->resultFile());
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

        $item = app(WorkService::class)->startAndDrive(DocumentsWork::KEY, $this->parameters($records, true), $user);

        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertSame([3, 3, 0, []], [$item->result['generated'], $item->result['stored'], $item->result['sent'], $item->result['notes']]);
        $this->assertSame('Rechnung-'.now()->format('Y-m-d').'.pdf', $item->resultFile());
        $this->assertSame('3 Dokumente erstellt, 3 abgelegt', $item->message);
        $content = $this->resultFile($item);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThanOrEqual(3, self::pages($content), 'Je Dokument mindestens eine Seite.');
        $this->assertSame(3, RecordFile::query()->whereNotNull('sha256')->count());
        $this->assertSame(3, Activity::query()->where('event', DocumentGenerator::EVENT)->count(), 'Je Datensatz im Verlauf.');
        // Die Einzeldokumente sind aufgeräumt, nur das Ergebnis bleibt.
        $this->assertSame([$item->directory().'/'.$item->resultFile()], Storage::disk('local')->files($item->directory()));
    }

    public function test_steps_create_at_least_one_document_each_and_assemble_at_the_end(): void
    {
        $user = $this->clerk(['view', 'update']);
        $this->actingAs($user);
        $records = $this->receipts(3);
        $runner = app(WorkRunner::class);

        // Budget 0: ein Dokument je Schritt, der Fortschritt wandert mit, die Ablage steht nach jedem Schritt fest.
        $item = $runner->advance(app(WorkService::class)->start(DocumentsWork::KEY, $this->parameters($records, true), $user), 0);
        $this->assertSame([WorkItem::RUNNING, 1, 3, 1, 1], [$item->status, $item->done, $item->total, $item->state['index'], $item->state['generated']]);
        $this->assertCount(1, $item->state['documents']);
        $this->assertSame(1, RecordFile::query()->whereNotNull('sha256')->count());

        $item = $runner->advance(WorkItem::query()->findOrFail($item->id), 0);
        $item = $runner->advance($item, 0);
        $this->assertSame([WorkItem::RUNNING, 3, 3], [$item->status, $item->done, $item->state['index']], 'Alle erzeugt, das Zusammenführen ist ein eigener Schritt');

        $item = $runner->advance($item, 0);
        $this->assertSame([WorkItem::DONE, 3, 3], [$item->status, $item->result['generated'], $item->result['stored']]);
        $this->assertStringStartsWith('%PDF-', $this->resultFile($item));
    }

    public function test_zip_with_one_file_per_document_and_notes_when_storing_is_not_allowed(): void
    {
        $reader = $this->clerk(['view']);
        $this->actingAs($reader);

        $item = app(WorkService::class)->startAndDrive(DocumentsWork::KEY, $this->parameters($this->receipts(2), true, DocumentBatch::ZIP), $reader);

        $this->assertSame([WorkItem::DONE, 2, 0, 'Rechnung-'.now()->format('Y-m-d').'.zip'], [$item->status, $item->result['generated'], $item->result['stored'], $item->resultFile()]);
        $this->assertCount(2, $item->result['notes']);
        $this->assertStringContainsString('nicht abgelegt', $item->result['notes'][0]);
        $this->assertStringContainsString('nicht abgelegt', (string) $item->message, 'Hinweise stehen auch in der Meldung der Arbeit');

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($item->directory().'/'.$item->resultFile()));
        $names = array_map(static fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();

        $this->assertCount(2, array_unique($names));
        $this->assertStringStartsWith('Rechnung-', $names[0]);
        $this->assertSame(0, RecordFile::query()->whereNotNull('sha256')->count());
    }

    public function test_optional_limit_is_checked_before_anything_is_created(): void
    {
        $this->assertSame(0, DocumentBatch::max(), 'Ab Werk ohne Grenze');
        config(['documents.batch_max' => 2]);
        $this->actingAs($this->clerk(['view', 'update']));

        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->selectTableRecords($this->receipts(3)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all())
            ->callAction(TestAction::make('documentBulk')->table()->bulk(), ['format' => DocumentBatch::PDF, 'store' => true])
            ->assertNotified('3 Datensätze ausgewählt – höchstens 2 auf einmal. Bitte weniger auswählen oder den Filter eingrenzen.');

        $this->assertSame(0, Activity::query()->where('event', DocumentGenerator::EVENT)->count());
        $this->assertSame(0, WorkItem::query()->where('type', DocumentsWork::KEY)->count());
    }

    public function test_a_batch_that_is_not_finished_in_the_request_continues_as_work(): void
    {
        app()->instance(TimeLimit::class, new class extends TimeLimit
        {
            public function budget(float $wanted, int $margin = 5): float
            {
                return 0.0;
            }
        });
        $user = $this->clerk(['view', 'update']);
        $this->actingAs($user);
        $records = $this->receipts(2);

        $component = Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->selectTableRecords($records->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all())
            ->callAction(TestAction::make('documentBulk')->table()->bulk(), ['format' => DocumentBatch::PDF, 'store' => true]);
        $item = WorkItem::query()->where('type', DocumentsWork::KEY)->latest('id')->firstOrFail();

        $component->assertRedirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));
        $this->assertSame([WorkItem::RUNNING, 1, 2], [$item->status, $item->done, $item->total]);

        $this->travel(1)->minutes();
        $this->artisan('work:run')->assertSuccessful();
        $item->refresh();
        $this->assertSame([WorkItem::DONE, 2, 2], [$item->status, $item->result['generated'], $item->result['stored']]);
        $this->get(route('work.download', $item->uuid))->assertOk()->assertHeader('content-disposition', 'attachment; filename='.$item->resultFile());
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
