<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Files\FileKinds;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Support\ActivityChanges;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Feldtyp „Datei“ (B-021): hochladen, zuordnen, ausliefern nur mit Leserecht, löschen mit Datensatz, Feld und Tabelle.
 */
class FileFieldTest extends DynamicDataTestCase
{
    private CustomTable $vertraege;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->vertraege = $this->service()->createTable(['name' => 'vertraege', 'label' => 'Verträge', 'export_enabled' => true], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'belege', 'label' => 'Belege', 'type' => 'file', 'options' => ['kinds' => ['images', 'pdf'], 'max_mb' => 2, 'max_files' => 3]],
        ]);
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
    }

    /** Echtes PNG (ohne GD erzeugt), damit Typ und Abmessungen erkannt werden. */
    private static function png(string $name, int $width = 4, int $height = 2): UploadedFile
    {
        $row = "\x00".str_repeat("\xff\x00\x00", $width);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    private function files(): RecordFiles
    {
        return app(RecordFiles::class);
    }

    /** Datei ablegen wie das Formular und dem neuen Datensatz zuordnen. */
    private function recordWithFile(string $name = 'scan.png'): array
    {
        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $file = $this->files()->store($this->vertraege->fresh(), $field, self::png($name, 400, 200));
        $record = DynamicRecord::forTable($this->vertraege)->create(['titel' => 'Miete', 'belege' => [$file->toReference()]]);

        return [$record, $file->fresh()];
    }

    public function test_field_options_are_normalized(): void
    {
        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();

        $this->assertSame(['images', 'pdf'], $field->options['kinds']);
        $this->assertSame(2, $field->options['max_mb']);
        $this->assertSame(3, $field->options['max_files']);
        $this->assertFalse((bool) $field->is_unique);
    }

    public function test_upload_through_the_form_stores_the_file_and_assigns_it(): void
    {
        Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->callTableAction('create', data: ['titel' => 'Leasing', 'belege' => [self::png('vertrag.png', 300, 300)]])
            ->assertHasNoTableActionErrors();

        $record = DynamicRecord::forTable($this->vertraege)->newQuery()->where('titel', 'Leasing')->sole();
        $file = RecordFile::query()->sole();

        $this->assertSame([['id' => $file->id, 'name' => 'vertrag.png']], $record->belege);
        $this->assertSame((string) $record->getKey(), $file->record_id);
        $this->assertSame([300, 300], [$file->width, $file->height]);
        $this->assertStringStartsWith('files/vertraege/belege/', $file->path);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_forbidden_file_kinds_are_rejected(): void
    {
        Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->callTableAction('create', data: ['titel' => 'Text', 'belege' => [UploadedFile::fake()->create('notiz.txt', 1, 'text/plain')]])
            ->assertHasTableActionErrors();

        $this->assertSame(0, DynamicRecord::forTable($this->vertraege)->newQuery()->count());
    }

    public function test_download_requires_read_access(): void
    {
        [, $file] = $this->recordWithFile();

        $this->get(route('files.show', ['file' => $file->id]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png');
        // Vorschaubild mit GD als JPEG, ohne GD das Original.
        $this->get(route('files.show', ['file' => $file->id, 'thumb' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', function_exists('imagecreatefromstring') ? 'image/jpeg' : 'image/png');

        $this->actingAs(User::factory()->create());
        $this->get(route('files.show', ['file' => $file->id]))->assertNotFound();

        $this->actingAs(User::factory()->create()->givePermissionTo('data.vertraege.view'));
        $this->get(route('files.show', ['file' => $file->id]))->assertOk();

        auth()->logout();
        $this->get(route('files.show', ['file' => $file->id]))->assertRedirect();
    }

    public function test_unassigned_upload_is_only_visible_to_the_uploader(): void
    {
        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $file = $this->files()->store($this->vertraege, $field, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $this->get(route('files.show', ['file' => $file->id]))->assertOk();
        $this->actingAs($this->superAdmin());
        $this->get(route('files.show', ['file' => $file->id]))->assertNotFound();
    }

    public function test_trashed_record_files_need_delete_permission(): void
    {
        [$record, $file] = $this->recordWithFile();
        $record->delete();

        $this->actingAs(User::factory()->create()->givePermissionTo('data.vertraege.view'));
        $this->get(route('files.show', ['file' => $file->id]))->assertNotFound();

        $this->actingAs(User::factory()->create()->givePermissionTo(['data.vertraege.view', 'data.vertraege.delete']));
        $this->get(route('files.show', ['file' => $file->id]))->assertOk();
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_replaced_files_and_force_deleted_records_lose_their_files(): void
    {
        [$record, $old] = $this->recordWithFile('alt.png');
        [, $other] = $this->recordWithFile('fremd.png');

        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $new = $this->files()->store($this->vertraege, $field, self::png('neu.png'));

        // Fremde Datei lässt sich nicht „übernehmen“; ersetzte wird gelöscht.
        $record->update(['belege' => [$new->toReference(), $other->toReference()]]);
        $this->assertNull(RecordFile::query()->find($old->id));
        Storage::disk('local')->assertMissing($old->path);
        $this->assertSame((string) $record->getKey(), $new->fresh()->record_id);
        $this->assertNotSame((string) $record->getKey(), $other->fresh()->record_id);

        $record->forceDelete();
        $this->assertNull(RecordFile::query()->find($new->id));
        Storage::disk('local')->assertMissing($new->path);
        Storage::disk('local')->assertExists($other->path);
    }

    public function test_dropping_the_field_removes_the_files(): void
    {
        [, $file] = $this->recordWithFile();
        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $this->service()->updateField($field, ['is_active' => false]);
        $this->service()->dropField($field->fresh(), 'belege');

        $this->assertSame(0, RecordFile::query()->count());
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_dropping_the_table_removes_the_files(): void
    {
        [, $file] = $this->recordWithFile();
        $this->service()->updateTable($this->vertraege, ['is_active' => false]);
        $this->service()->dropTable($this->vertraege->fresh(), 'vertraege');

        $this->assertSame(0, RecordFile::query()->count());
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_list_summary_filter_export_and_audit_show_the_names(): void
    {
        [$record, $file] = $this->recordWithFile('scan<1>.png');
        $ohne = DynamicRecord::forTable($this->vertraege)->create(['titel' => 'Ohne']);

        $html = DynamicTableBuilder::fileSummary($record->belege);
        $this->assertStringContainsString(e(route('files.show', ['file' => $file->id, 'thumb' => 1])), $html);
        $this->assertStringContainsString('scan&lt;1&gt;.png', $html);
        $this->assertStringNotContainsString('scan<1>', $html);

        Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->filterTable('belege', true)
            ->assertCanSeeTableRecords([$record])
            ->assertCanNotSeeTableRecords([$ohne])
            ->filterTable('belege', false)
            ->assertCanSeeTableRecords([$ohne])
            ->assertCanNotSeeTableRecords([$record])
            ->resetTableFilters();

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'vertraege']);
        $page->callTableAction('export_csv');
        $csv = (string) ($page->effects['download']['content'] ?? '');
        $this->assertStringContainsString('scan<1>.png', base64_decode($csv, true) ?: $csv);

        $activity = Activity::query()->where('subject_id', $record->getKey())->where('event', 'created')->latest('id')->firstOrFail();
        $this->assertStringContainsString('belege: scan<1>.png', ActivityChanges::summary($activity));
    }

    public function test_livewire_accepts_uploads_up_to_the_designer_limit(): void
    {
        $this->assertContains('max:'.((int) config('dynamic_data.files.limit_max_mb') * 1024), config('livewire.temporary_file_upload.rules'));
    }

    public function test_upload_size_never_exceeds_the_php_limit(): void
    {
        $limit = FileKinds::phpLimitMb();
        $expected = min(2, max(1, $limit ?? PHP_INT_MAX)) * 1024;

        Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->mountTableAction('create')
            ->assertFormFieldExists('belege', fn (FileUpload $c): bool => $c->getMaxSize() === $expected);
    }

    public function test_specific_kinds_win_over_any(): void
    {
        $this->assertSame(['images'], FileKinds::normalize(['images', 'any']));
        $this->assertSame(['any'], FileKinds::normalize([]));
        $this->assertNotContains('text/plain', FileKinds::mimes(['images', 'any']));

        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $this->service()->updateField($field, ['options' => ['kinds' => ['images', 'any'], 'max_mb' => 2, 'max_files' => 3]]);
        $this->assertSame(['images'], $field->fresh()->options['kinds']);

        Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->callTableAction('create', data: ['titel' => 'Text', 'belege' => [UploadedFile::fake()->create('notiz.txt', 1, 'text/plain')]])
            ->assertHasTableActionErrors();
    }

    public function test_details_show_images_and_other_files(): void
    {
        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $pdf = $this->files()->store($this->vertraege, $field, UploadedFile::fake()->create('vertrag.pdf', 10, 'application/pdf'));
        [$record, $image] = $this->recordWithFile();
        $record->update(['belege' => [$image->toReference(), $pdf->toReference()]]);

        $html = Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->call('selectRecord', $record->getKey())
            ->assertSeeHtml('data-field-blocks')
            ->html();

        $this->assertStringContainsString('vertrag.pdf', $html);
        $this->assertStringContainsString(e(route('files.show', ['file' => $image->id, 'thumb' => 1])), $html);
    }

    public function test_edit_form_shows_the_stored_file_of_a_single_file_field(): void
    {
        $this->service()->createTable(['name' => 'lieferanten', 'label' => 'Lieferanten'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
            ['name' => 'vertrag', 'label' => 'Vertrag', 'type' => 'file', 'options' => ['kinds' => ['pdf'], 'max_files' => 1]],
        ]);
        $table = CustomTable::query()->where('name', 'lieferanten')->firstOrFail();
        $field = $table->fields()->where('name', 'vertrag')->firstOrFail();
        $pdf = $this->files()->store($table, $field, UploadedFile::fake()->create('v.pdf', 10, 'application/pdf'));
        $record = DynamicRecord::forTable($table)->create(['name' => 'A', 'vertrag' => [$pdf->toReference()]]);
        $edit = [TestAction::make('details')->table($record), TestAction::make('edit')];

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'lieferanten'])->mountAction($edit);
        $this->assertSame([$pdf->path], array_values((array) $page->get('mountedActions.1.data.vertrag')));

        // Unverändert speichern behält die Datei.
        $page->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame([$pdf->toReference()], DynamicRecord::forTable($table)->newQuery()->findOrFail($record->getKey())->vertrag);
        $this->assertNotNull(RecordFile::query()->find($pdf->id));
    }

    public function test_files_carry_preview_data_and_can_be_forced_to_download(): void
    {
        [$record, $image] = $this->recordWithFile('bild.png');
        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $pdf = $this->files()->store($this->vertraege, $field, UploadedFile::fake()->create('vertrag.pdf', 10, 'application/pdf'));
        $text = $this->files()->store($this->vertraege, $field, UploadedFile::fake()->create('notiz.txt', 1, 'text/plain'));
        $references = [$image->toReference(), $pdf->toReference(), $text->toReference()];

        $html = DynamicTableBuilder::fileSummary($references, limit: 2);
        preg_match('/data-saft-files="([^"]+)"/', (string) $html, $match);
        $files = json_decode(html_entity_decode($match[1]), true);

        $this->assertSame(['image', 'pdf', null], array_column($files, 'preview'));
        $this->assertSame(route('files.show', ['file' => $text->id]), $files[2]['url']);
        $this->assertStringContainsString('data-saft-file="2"', (string) $html, '„+1“ öffnet die Vorschau bei der dritten Datei.');

        $fourth = $this->files()->store($this->vertraege, $field, self::png('viertes.png'));
        $record->update(['belege' => [...$references, $fourth->toReference()]]);
        $this->get(route('files.show', ['file' => $pdf->id]))->assertHeader('Content-Disposition', 'inline; filename=vertrag.pdf');
        $this->get(route('files.show', ['file' => $pdf->id, 'download' => 1]))->assertHeader('Content-Disposition', 'attachment; filename=vertrag.pdf');

        // In der Liste unverändert (nicht von Filament bereinigt): Vorschau-Daten, Klick-Handler und „+1“.
        $this->get('/admin/data/vertraege')->assertOk()->assertSee('data-saft-file-preview', false)->assertSee('window.saftFilePreview', false);
        Livewire::test(DynamicDataPage::class, ['table' => 'vertraege'])
            ->call('loadTable')
            ->assertSeeHtml('data-saft-files=')
            ->assertSeeHtml('window.saftFilePreview(this, true)')
            ->assertSeeHtml('class="saft-file-more"');
    }

    public function test_drop_dialogs_warn_about_the_files(): void
    {
        $this->assertNull(RecordFiles::dropWarning($this->vertraege));
        $this->recordWithFile('a.png');
        [$trashed] = $this->recordWithFile('b.png');
        $trashed->delete();

        $size = Number::fileSize((int) RecordFile::query()->sum('size'), precision: 1);
        $this->assertSame("Dabei werden auch 2 Dateien ({$size}) endgültig gelöscht.", RecordFiles::dropWarning($this->vertraege));
        $this->assertSame("Dabei werden auch 2 Dateien ({$size}) endgültig gelöscht.", RecordFiles::dropWarning($this->vertraege, 'belege'));
        $this->assertNull(RecordFiles::dropWarning($this->vertraege, 'titel'));

        $field = $this->vertraege->fields()->where('name', 'belege')->firstOrFail();
        $this->service()->updateField($field, ['is_active' => false]);
        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $this->vertraege->fresh(), 'pageClass' => EditCustomTable::class])
            ->mountAction(TestAction::make('dropField')->table($field->fresh()))
            ->assertActionMounted(TestAction::make('dropField')->table($field->fresh()));

        $this->service()->updateTable($this->vertraege, ['is_active' => false]);
        Livewire::test(EditCustomTable::class, ['record' => $this->vertraege->id])
            ->mountAction(TestAction::make('dropTable'))
            ->assertActionMounted(TestAction::make('dropTable'));
    }
}
