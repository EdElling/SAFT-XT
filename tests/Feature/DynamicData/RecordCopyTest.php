<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Files\RecordFiles;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Datensatz kopieren (B-120): Formular vorbelegt, eindeutige Felder leer, Dateien als echte Kopien. */
class RecordCopyTest extends DynamicDataTestCase
{
    private CustomTable $items;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->items = $this->service()->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_unique' => true],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'is_required' => true],
            ['name' => 'kategorie', 'label' => 'Kategorie', 'type' => 'select', 'options' => ['options' => [['value' => 'Instrument'], ['value' => 'Zubehör']]]],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'wert', 'label' => 'Wert', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'preis * menge']],
            ['name' => 'bilder', 'label' => 'Bilder', 'type' => 'file', 'options' => ['kinds' => ['any'], 'max_files' => 10]],
        ]);
        $this->actingAs($this->superAdmin());
    }

    private function original(): DynamicRecord
    {
        $field = $this->items->fields()->where('name', 'bilder')->firstOrFail();
        $files = array_map(fn (string $name): RecordFile => app(RecordFiles::class)->store($this->items->fresh(), $field, UploadedFile::fake()->createWithContent($name, 'Inhalt '.$name)), ['vorne.txt', 'hinten.txt']);

        return DynamicRecord::forTable($this->items->fresh('fields'))->create([
            'nummer' => 'GIT-001', 'bezeichnung' => 'Gibson Les Paul', 'kategorie' => 'Instrument', 'preis' => '1200.00', 'menge' => 2,
            'bilder' => array_map(static fn (RecordFile $f): array => $f->toReference(), $files),
        ]);
    }

    public function test_copy_prefills_the_form_and_copies_the_files(): void
    {
        $original = $this->original();
        $before = RecordFile::query()->where('record_id', (string) $original->getKey())->orderBy('name')->get();

        Livewire::test(DynamicDataPage::class, ['table' => 'artikel'])
            ->mountAction(TestAction::make('copyRow')->table($original))
            // Vorbelegt mit allen Werten ausser dem eindeutigen Feld; Dateien des Originals im Formular.
            ->assertSchemaStateSet(['nummer' => null, 'bezeichnung' => 'Gibson Les Paul', 'kategorie' => 'Instrument', 'menge' => 2])
            ->setActionData(['nummer' => 'GIT-002', 'bezeichnung' => 'Gibson Les Paul (2)'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Kopie angelegt.');

        $copy = DynamicRecord::forTableName('artikel')->newQuery()->where('nummer', 'GIT-002')->sole();
        $this->assertSame(['Gibson Les Paul (2)', 'Instrument', '2400.00'], [$copy->bezeichnung, $copy->kategorie, (string) $copy->wert], 'Berechnet neu gerechnet.');

        // Eigene Dateien mit gleichem Inhalt, aber anderen Kennungen und Pfaden.
        $copied = RecordFile::query()->where('record_id', (string) $copy->getKey())->orderBy('name')->get();
        $this->assertSame(['hinten.txt', 'vorne.txt'], $copied->pluck('name')->all());
        $this->assertEmpty(array_intersect($copied->pluck('id')->all(), $before->pluck('id')->all()));
        $this->assertSame($copied->pluck('id')->sort()->values()->all(), collect($copy->bilder)->pluck('id')->sort()->values()->all());

        foreach ($copied as $file) {
            Storage::disk('local')->assertExists($file->path);
            $this->assertSame('Inhalt '.$file->name, Storage::disk('local')->get($file->path));
        }

        // Das Original löschen lässt die Dateien der Kopie unberührt.
        $original->forceDelete();
        Storage::disk('local')->assertMissing($before->first()->path);
        Storage::disk('local')->assertExists($copied->first()->path);
    }

    public function test_cancelled_copy_leaves_no_files_and_needs_the_create_permission(): void
    {
        $original = $this->original();
        $count = RecordFile::query()->count();

        Livewire::test(DynamicDataPage::class, ['table' => 'artikel'])
            ->mountAction(TestAction::make('copyRow')->table($original))
            ->unmountAction();
        $this->assertSame($count, RecordFile::query()->count(), 'Erst beim Speichern wird kopiert.');

        $this->actingAs($this->userWithPermissions(['data.artikel.view', 'data.artikel.update']));
        Livewire::test(DynamicDataPage::class, ['table' => 'artikel'])
            ->assertActionHidden(TestAction::make('copyRow')->table($original));
    }
}
