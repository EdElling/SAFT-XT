<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\History\HistoryUndo;
use App\DynamicData\Ui\DynamicRecordActions;
use App\DynamicData\Ui\HistoryUndoActions;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Support\ActivityChanges;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** B-173: einen Eintrag „Geändert“ im Verlauf zurücknehmen, je Feld wählbar. */
class HistoryUndoTest extends DynamicDataTestCase
{
    private CustomTable $kurse;

    private RecordWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());
        $this->writer = app(RecordWriter::class);

        $this->kurse = $this->service()->createTable(['name' => 'kurse', 'label' => 'Kurse'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'kuerzel', 'label' => 'Kürzel', 'type' => 'string', 'is_unique' => true],
            ['name' => 'wochentag', 'label' => 'Wochentag', 'type' => 'select', 'options' => ['options' => [['value' => 'mo', 'label' => 'Montag'], ['value' => 'do', 'label' => 'Donnerstag']]]],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'beginn', 'label' => 'Beginn', 'type' => 'date'],
            ['name' => 'doppelt', 'label' => 'Doppelt', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'tarif * 2']],
        ]);
    }

    private function course(array $values = []): DynamicRecord
    {
        return $this->writer->create($this->kurse, [
            'titel' => 'Kurs A', 'kuerzel' => 'KA', 'wochentag' => 'mo', 'tarif' => '120', 'beginn' => '2026-08-17', ...$values,
        ], WriteOptions::ui());
    }

    private function update(DynamicRecord $record, array $values, ?WriteOptions $options = null): DynamicRecord
    {
        return $this->writer->update($record->refresh(), $values, $options ?? WriteOptions::ui());
    }

    private function lastEntry(DynamicRecord $record): Activity
    {
        return Activity::query()->where('subject_type', 'dynamic:kurse')->where('subject_id', $record->getKey())->where('event', 'updated')->orderByDesc('id')->firstOrFail();
    }

    private function undo(Activity $entry, DynamicRecord $record): HistoryUndo
    {
        return HistoryUndo::of((int) $entry->getKey(), $this->kurse, $record->refresh()) ?? $this->fail('Eintrag gehört zum Datensatz');
    }

    /** Wie in der Oberfläche: Verlauf der Zeile öffnen, darin „Rückgängig“ (verschachtelt). */
    private function openUndo(DynamicRecord $record, Activity $entry): Testable
    {
        return Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction(TestAction::make('historyRow')->table($record))
            ->mountAction(TestAction::make(HistoryUndoActions::UNDO)->arguments(['entry' => $entry->getKey()]));
    }

    /** @return array<string, string> Feld => Einordnung */
    private function statuses(HistoryUndo $undo): array
    {
        return array_column($undo->fields(), 'status', 'name');
    }

    public function test_fields_are_classified_against_todays_values(): void
    {
        $course = $this->course();
        $this->update($course, ['titel' => 'Kurs B', 'wochentag' => 'do', 'tarif' => '150', 'beginn' => '2026-09-01']);
        $entry = $this->lastEntry($course);
        // Seither: Titel nochmals geändert, Wochentag von Hand zurück.
        $this->update($course, ['titel' => 'Kurs C', 'wochentag' => 'mo']);

        $undo = $this->undo($entry, $course);

        $this->assertSame([
            'titel' => HistoryUndo::CHANGED,
            'wochentag' => HistoryUndo::ALREADY,
            'tarif' => HistoryUndo::SELECT,
            'beginn' => HistoryUndo::SELECT,
            'doppelt' => HistoryUndo::FIXED,
        ], $this->statuses($undo));
        $this->assertSame(['tarif', 'beginn'], $undo->preselected());

        $tarif = collect($undo->fields())->firstWhere('name', 'tarif');
        $this->assertSame(['150,00', '120,00'], [$tarif['now'], $tarif['back']]);
        $this->assertSame('computed', collect($undo->fields())->firstWhere('name', 'doppelt')['reason']);
        $this->assertTrue($undo->offered());

        $this->assertSame(['titel' => 'Kurs A', 'tarif' => '120.00'], array_map(strval(...), $undo->values(['titel', 'tarif', 'wochentag', 'doppelt'])));
    }

    public function test_undo_from_the_history_dialog_writes_old_values_and_marks_the_entry(): void
    {
        $course = $this->course();
        $this->update($course, ['wochentag' => 'do', 'tarif' => '150']);
        $entry = $this->lastEntry($course);

        $this->openUndo($course, $entry)
            ->assertMountedActionModalSee(['Tarif: 150,00 → zurück auf 120,00', 'Wochentag: Donnerstag → zurück auf Montag', 'Berechnet SAFT selbst'])
            ->assertActionDataSet(['fields' => ['wochentag', 'tarif']])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified('2 Felder zurückgenommen');

        $course->refresh();
        $this->assertSame(['mo', '120.00', '240.00'], [$course->wochentag, (string) $course->getRawOriginal('tarif'), (string) $course->getRawOriginal('doppelt')]);

        $revert = $this->lastEntry($course);
        $this->assertSame($entry->getKey(), $revert->properties['reverts']);
        $this->assertSame(WriteOptions::UI, $revert->properties['via']);
        $this->assertSame('geändert (Rückgängig)', ActivityChanges::eventText($revert));
        $this->assertStringStartsWith('geändert (Rückgängig)', DynamicRecordActions::historyRows($course, $this->kurse)[0]['what']);
    }

    /** Smartphone: Details → Verlauf → Rückgängig, drei Ebenen tief. */
    public function test_undo_also_works_from_the_details_slider(): void
    {
        $course = $this->course();
        $this->update($course, ['tarif' => '150']);

        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction(TestAction::make('details')->table($course))
            ->mountAction(TestAction::make('history'))
            ->mountAction(TestAction::make(HistoryUndoActions::UNDO)->arguments(['entry' => $this->lastEntry($course)->getKey()]))
            ->callMountedAction()
            ->assertNotified('1 Feld zurückgenommen');

        $this->assertSame('120.00', (string) $course->refresh()->getRawOriginal('tarif'));
    }

    public function test_only_ticked_fields_are_reverted_and_an_undo_can_be_undone(): void
    {
        $course = $this->course();
        $this->update($course, ['wochentag' => 'do', 'tarif' => '150']);

        $this->openUndo($course, $this->lastEntry($course))
            ->fillForm(['fields' => ['tarif']])
            ->callMountedAction()
            ->assertNotified('1 Feld zurückgenommen');
        $this->assertSame(['do', '120.00'], [$course->refresh()->wochentag, (string) $course->getRawOriginal('tarif')]);

        // Die Rücknahme selbst lässt sich zurücknehmen.
        $this->openUndo($course, $this->lastEntry($course))->callMountedAction();
        $this->assertSame('150.00', (string) $course->refresh()->getRawOriginal('tarif'));
    }

    public function test_nothing_ticked_and_failing_checks_keep_the_confirmation_open(): void
    {
        $course = $this->course();
        $this->update($course, ['kuerzel' => 'KB']);
        $entry = $this->lastEntry($course);
        // Das alte Kürzel ist inzwischen vergeben.
        $this->course(['titel' => 'Kurs Z', 'kuerzel' => 'KA']);

        $this->openUndo($course, $entry)->fillForm(['fields' => []])->callMountedAction()->assertHasFormErrors(['fields' => 'required']);

        $this->openUndo($course, $entry)->callMountedAction()
            ->assertNotified('Nicht zurückgenommen')
            ->assertActionMounted(TestAction::make(HistoryUndoActions::UNDO)->arguments(['entry' => $entry->getKey()]));
        $this->assertSame('KB', $course->refresh()->kuerzel);
    }

    public function test_a_save_in_between_is_reported_without_overwriting(): void
    {
        $course = $this->course();
        $this->update($course, ['tarif' => '150']);
        $page = $this->openUndo($course, $this->lastEntry($course));

        $this->update($course, ['titel' => 'Kurs B']);
        $page->callMountedAction()->assertNotified('Inzwischen geändert');

        $this->assertSame('150.00', (string) $course->refresh()->getRawOriginal('tarif'));
    }

    public function test_symbol_needs_the_right_to_edit_an_updated_entry_and_a_revertable_field(): void
    {
        $course = $this->course();
        $this->update($course, ['tarif' => '150']);

        $rows = collect(DynamicRecordActions::historyRows($course, $this->kurse));
        $this->assertSame([true, false], $rows->pluck('undo')->all(), 'Geändert ja, Angelegt nein');

        // Nur eine Summe/berechnetes Feld im Eintrag: kein Symbol.
        $entry = (new Activity)->forceFill(['event' => 'updated', 'attribute_changes' => ['old' => ['doppelt' => '1'], 'attributes' => ['doppelt' => '2']]]);
        $this->assertFalse((new HistoryUndo($entry, $this->kurse, $course->refresh()))->offered());

        $this->actingAs(User::factory()->create()->givePermissionTo('data.kurse.view'));
        $this->assertSame([false, false], collect(DynamicRecordActions::historyRows($course, $this->kurse))->pluck('undo')->all());
        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction(TestAction::make('historyRow')->table($course))
            ->assertActionHidden(TestAction::make(HistoryUndoActions::UNDO)->arguments(['entry' => $this->lastEntry($course)->getKey()]));
    }

    public function test_an_entry_of_a_bulk_edit_can_be_undone_on_its_own(): void
    {
        $course = $this->course();
        $this->update($course, ['tarif' => '150'], WriteOptions::bulk('bulk:test'));

        $this->openUndo($course, $this->lastEntry($course))->callMountedAction();

        $this->assertSame('120.00', (string) $course->refresh()->getRawOriginal('tarif'));
        $this->assertArrayNotHasKey('run', $this->lastEntry($course)->properties->all(), 'Einzelner Schreibvorgang ohne Kennung');
    }

    public function test_old_entries_with_utc_dates_are_compared_in_local_time(): void
    {
        config(['app.timezone' => 'Europe/Zurich']);
        $course = $this->course(['beginn' => '2026-09-01']);
        $entry = (new Activity)->forceFill(['event' => 'updated', 'attribute_changes' => [
            'old' => ['beginn' => '2026-08-17'],
            'attributes' => ['beginn' => '2026-08-31T22:00:00.000000Z'],
        ]]);

        $undo = new HistoryUndo($entry, $this->kurse, $course->refresh());

        $this->assertSame(['beginn' => HistoryUndo::SELECT], $this->statuses($undo));
        $this->assertSame(['beginn' => '2026-08-17'], $undo->values(['beginn']));
    }

    public function test_an_entry_of_another_record_is_not_found(): void
    {
        $course = $this->course();
        $other = $this->course(['titel' => 'Kurs B', 'kuerzel' => 'KB']);
        $this->update($other, ['tarif' => '150']);

        $this->assertNull(HistoryUndo::of((int) $this->lastEntry($other)->getKey(), $this->kurse, $course));
    }
}
