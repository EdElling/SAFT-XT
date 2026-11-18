<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Exceptions\BusinessRuleException;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Plugins\Api\Saft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Dateien aus Dateifeldern über die Plugin-API (seit 1.8): lesen, anhängen mit Prüfungen, entfernen. */
class PluginFileApiTest extends DynamicDataTestCase
{
    private DynamicRecord $record;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->service()->createTable(['name' => 'vertraege', 'label' => 'Verträge'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'scan', 'label' => 'Scan', 'type' => 'file', 'options' => ['kinds' => ['pdf', 'text'], 'max_mb' => 1, 'max_files' => 2]],
        ]);
        $this->record = DynamicRecord::forTableName('vertraege')->create(['titel' => 'Miete']);
        $this->directory = sys_get_temp_dir().'/saft-plugin-files-'.uniqid();
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function file(string $name, string $content): string
    {
        File::put($this->directory.'/'.$name, $content);

        return $this->directory.'/'.$name;
    }

    public function test_files_are_attached_read_and_detached(): void
    {
        $this->actingAs($this->superAdmin());
        $table = Saft::table('vertraege');

        $stored = $table->attachFile($this->record, 'scan', $this->file('tmp-123.txt', 'Vertragstext'), 'Vertrag.txt');
        $table->attachFile($this->record->getKey(), 'scan', $this->file('anhang.txt', 'Anhang'));

        $files = $table->files($this->record->getKey(), 'scan');
        $this->assertSame(['Vertrag.txt', 'anhang.txt'], array_map(static fn ($f): string => $f->name, $files));
        $this->assertSame('Vertragstext', $files[0]->contents());
        $this->assertSame('text/plain', $files[0]->mime);
        $this->assertSame(route('files.show', ['file' => $stored->id]), $stored->url());
        $this->assertSame((string) $this->record->getKey(), RecordFile::query()->find($stored->id)?->record_id);
        $this->assertTrue(Activity::query()->where('subject_id', $this->record->getKey())->where('event', 'updated')->exists(), 'Im Änderungsprotokoll.');

        $table->detachFile($this->record, 'scan', $stored->id);
        $this->assertSame(['anhang.txt'], array_map(static fn ($f): string => $f->name, $table->files($this->record, 'scan')));
        $this->assertNull(RecordFile::query()->find($stored->id));
    }

    public function test_attach_checks_kind_size_and_number(): void
    {
        $this->actingAs($this->superAdmin());
        $table = Saft::table('vertraege');

        $errors = [];
        foreach ([
            'art' => $this->file('bild.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 32)),
            'grösse' => $this->file('gross.txt', str_repeat('x', 1024 * 1024 + 1)),
        ] as $case => $path) {
            try {
                $table->attachFile($this->record, 'scan', $path);
                $this->fail("Angehängt trotz falscher {$case}.");
            } catch (ValidationException $e) {
                $errors[] = $e->errors()['scan'][0];
            }
        }

        $this->assertStringEndsWith('sind nicht erlaubt.', $errors[0]);
        $this->assertSame('Scan: Die Datei ist grösser als 1 MB.', $errors[1]);

        $table->attachFile($this->record, 'scan', $this->file('a.txt', 'Erster Anhang'));
        $table->attachFile($this->record, 'scan', $this->file('b.txt', 'Zweiter Anhang'));
        $this->expectExceptionMessage('Scan: Es sind höchstens 2 Dateien erlaubt.');
        $table->attachFile($this->record, 'scan', $this->file('c.txt', 'Dritter Anhang'));
    }

    public function test_permissions_and_field_type_are_checked_and_create_cannot_take_files(): void
    {
        $this->actingAs($this->userWithPermissions(['data.vertraege.view']));
        $table = Saft::table('vertraege');
        $this->assertSame([], $table->files($this->record, 'scan'));

        try {
            $table->attachFile($this->record, 'scan', $this->file('a.txt', 'Erster Anhang'));
            $this->fail('Ohne Recht angehängt.');
        } catch (AuthorizationException) {
            $this->assertSame(0, RecordFile::query()->count());
        }

        $this->actingAs($this->superAdmin());

        try {
            Saft::table('vertraege')->files($this->record, 'titel');
            $this->fail('Textfeld als Dateifeld gelesen.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('kein aktives Dateifeld', $e->getMessage());
        }

        // Fremde Dateien lassen sich nicht über create()/update() unterschieben.
        $this->expectException(ValidationException::class);
        Saft::table('vertraege')->create(['titel' => 'B', 'scan' => [['id' => 'fremd', 'name' => 'x.pdf']]]);
    }
}
