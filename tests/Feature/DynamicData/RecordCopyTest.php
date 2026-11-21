<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Files\RecordFiles;
use App\DynamicData\RecordCopier;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Datensatz kopieren (B-120): Formular vorbelegt, eindeutige Felder leer, Dateien als echte Kopien; Kind-Datensätze (B-123). */
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

    /** Bestellung → Positionen → Seriennummern, je mit „mitkopieren“; Bestellung mit Vorgänger (Selbstreferenz). */
    private function orders(): DynamicRecord
    {
        $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_unique' => true],
            ['name' => 'vorgaenger_id', 'label' => 'Vorgänger', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_fields' => ['nummer']]],
        ]);
        $this->service()->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_fields' => ['nummer'], 'copy_with_parent' => true]],
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_fields' => ['bezeichnung']]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'menge * preis']],
        ]);
        $this->service()->createTable(['name' => 'seriennummern', 'label' => 'Seriennummern'], [
            ['name' => 'position_id', 'label' => 'Position', 'type' => 'relation', 'options' => ['table' => 'positionen', 'display_fields' => ['menge'], 'copy_with_parent' => true]],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
        ]);
        $orders = CustomTable::query()->where('name', 'bestellungen')->sole();
        $this->service()->addField($orders, ['name' => 'summe', 'label' => 'Summe', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'sum(positionen.betrag)']]);

        $guitar = $this->original();
        $first = DynamicRecord::forTableName('bestellungen')->create(['nummer' => 'B-1']);
        $order = DynamicRecord::forTableName('bestellungen')->create(['nummer' => 'B-2', 'vorgaenger_id' => $first->id]);
        $a = DynamicRecord::forTableName('positionen')->create(['bestellung_id' => $order->id, 'artikel_id' => $guitar->id, 'menge' => 1, 'preis' => '1200.00']);
        $b = DynamicRecord::forTableName('positionen')->create(['bestellung_id' => $order->id, 'artikel_id' => $guitar->id, 'menge' => 2, 'preis' => '15.00']);
        foreach ([[$a, 'SN-1'], [$b, 'SN-2'], [$b, 'SN-3']] as [$position, $serial]) {
            DynamicRecord::forTableName('seriennummern')->create(['position_id' => $position->id, 'nummer' => $serial]);
        }

        return DynamicRecord::forTableName('bestellungen')->newQuery()->findOrFail($order->id);
    }

    public function test_child_records_are_copied_along_over_two_levels(): void
    {
        $order = $this->orders();
        $orders = CustomTable::query()->where('name', 'bestellungen')->sole();
        $this->assertSame(['positionen.bestellung_id' => 'Positionen (2) – darin Seriennummern (3)'], app(RecordCopier::class)->options($orders, $order));

        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->mountAction(TestAction::make('copyRow')->table($order))
            ->assertSchemaStateSet(['_copy_children' => ['positionen.bestellung_id'], 'vorgaenger_id' => $order->vorgaenger_id])
            ->assertFormFieldVisible('_copy_children')
            ->setActionData(['nummer' => 'B-3'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Kopie angelegt.');

        $copy = DynamicRecord::forTableName('bestellungen')->newQuery()->where('nummer', 'B-3')->sole();
        // Selbstreferenz: derselbe Vorgänger wie das Original, der Vorgänger selbst wird nicht kopiert.
        $this->assertSame((int) $order->vorgaenger_id, (int) $copy->vorgaenger_id);
        $this->assertSame(3, DynamicRecord::forTableName('bestellungen')->newQuery()->count());
        $this->assertSame('1230.00', (string) $copy->summe, 'Summe über die kopierten Positionen.');

        $positions = DynamicRecord::forTableName('positionen')->newQuery()->where('bestellung_id', $copy->id)->orderBy('id')->get();
        $this->assertSame([1, 2], $positions->pluck('menge')->map(fn ($m): int => (int) $m)->all());
        $this->assertSame([(int) $order->id], DynamicRecord::forTableName('positionen')->newQuery()->where('bestellung_id', '<>', $copy->id)->distinct()->pluck('bestellung_id')->map(fn ($v): int => (int) $v)->all(), 'Originale unverändert.');
        $this->assertSame(1, $positions->pluck('artikel_id')->unique()->count(), 'Andere Verknüpfungen bleiben.');

        $serials = DynamicRecord::forTableName('seriennummern')->newQuery()->whereIn('position_id', $positions->pluck('id'))->orderBy('id')->pluck('nummer')->all();
        $this->assertSame(['SN-1', 'SN-2', 'SN-3'], $serials, 'Zweite Ebene zeigt auf die kopierten Positionen.');
    }

    public function test_children_can_be_deselected_and_blocking_fields_stop_the_copy(): void
    {
        $order = $this->orders();
        $orders = CustomTable::query()->where('name', 'bestellungen')->sole();
        $copier = app(RecordCopier::class);

        $copier->copy($orders->fresh('fields'), $order, ['nummer' => 'B-4']);
        $this->assertSame(2, DynamicRecord::forTableName('positionen')->newQuery()->count(), 'Ohne Häkchen keine Positionen.');

        // Eindeutige Pflichtfelder lassen sich nicht leeren: nichts wird angelegt.
        $serials = CustomTable::query()->where('name', 'seriennummern')->sole();
        $this->service()->updateField($serials->fields()->where('name', 'nummer')->sole(), ['is_unique' => true, 'is_required' => true]);
        try {
            $copier->copy($orders->fresh('fields'), $order, ['nummer' => 'B-5'], [], ['positionen.bestellung_id']);
            $this->fail('Abbruch erwartet.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('„Seriennummern“ lässt sich nicht mitkopieren: „Nummer“ ist eindeutig und Pflicht. Bitte das Häkchen entfernen.', $e->getMessage());
        }
        $this->assertFalse(DynamicRecord::forTableName('bestellungen')->newQuery()->where('nummer', 'B-5')->exists());

        // Obergrenze: Original und 5 Kind-Datensätze = 6.
        $this->service()->updateField($serials->fields()->where('name', 'nummer')->sole(), ['is_unique' => false, 'is_required' => false]);
        config(['dynamic_data.copy_max_records' => 5]);
        $this->expectExceptionMessage('Die Kopie umfasste 6, erlaubt sind höchstens 5.');
        $copier->copy($orders->fresh('fields'), $order, ['nummer' => 'B-6'], [], ['positionen.bestellung_id']);
    }

    public function test_the_option_is_refused_for_self_references_and_stored_for_children(): void
    {
        $this->orders();
        $orders = CustomTable::query()->where('name', 'bestellungen')->sole();

        $this->expectExceptionMessage('Eine Verknüpfung auf die eigene Tabelle (Hierarchie) kann nicht mitkopiert werden.');
        $this->service()->updateField($orders->fields()->where('name', 'vorgaenger_id')->sole(), ['options' => ['table' => 'bestellungen', 'display_fields' => ['nummer'], 'copy_with_parent' => true]]);
    }
}
