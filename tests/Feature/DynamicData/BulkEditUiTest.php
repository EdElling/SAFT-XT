<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Ui\BulkRecordActions;
use App\DynamicData\Ui\DynamicRecordActions;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Livewire\DynamicDetailTable;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;
use App\Work\TimeLimit;
use App\Work\Types\BulkRecordsWork;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/** Sammeländerung (B-133, S1b, S2): Dialog „Ändern …“ mit allen Arten, Löschen und Wiederherstellen in Datenansicht und Detailtabelle. */
class BulkEditUiTest extends DynamicDataTestCase
{
    private CustomTable $courses;

    private CustomTable $enrolments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->superAdmin());
        $this->courses = $this->service()->createTable(['name' => 'kurse', 'label' => 'Kurse'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
        ]);
        $this->enrolments = $this->service()->createTable(['name' => 'anmeldungen', 'label' => 'Anmeldungen'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                ['value' => 'warteliste', 'label' => ['de' => 'Warteliste']], ['value' => 'aktiv', 'label' => ['de' => 'Aktiv']],
            ]]],
            ['name' => 'bemerkung', 'label' => 'Bemerkung', 'type' => 'string'],
            ['name' => 'kurs_id', 'label' => 'Kurs', 'type' => 'relation', 'options' => ['table' => 'kurse', 'display_field' => 'titel']],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
        $this->service()->updateTable($this->courses, ['detail_field_ids' => [$this->fieldId('anmeldungen', 'kurs_id')]]);
    }

    private function bulkEdit(): TestAction
    {
        return TestAction::make('bulkEdit')->table()->bulk();
    }

    /** @return list<string> */
    private function enrol(int $count, ?int $course = null): array
    {
        $writer = app(RecordWriter::class);

        return array_map(fn (int $i): string => (string) $writer->create($this->enrolments, [
            'name' => "Person {$i}", 'status' => 'warteliste', 'bemerkung' => "B{$i}", 'kurs_id' => $course,
        ], WriteOptions::seed())->getKey(), range(1, $count));
    }

    /** @return array<int|string, mixed> */
    private function statuses(): array
    {
        return DynamicRecord::forTable($this->enrolments)->newQuery()->orderBy('id')->pluck('status', 'id')->all();
    }

    public function test_changes_the_selected_records_and_shows_the_result(): void
    {
        $ids = $this->enrol(3);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords([$ids[0], $ids[2]])
            ->assertActionVisible($this->bulkEdit())
            ->mountAction($this->bulkEdit())
            ->fillForm(['changes' => ['status' => ['on' => true, 'op' => 'set', 'status' => 'aktiv'], 'bemerkung' => ['on' => true, 'op' => 'clear']]])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('2 Datensätze geändert.');

        $this->assertSame(['aktiv', 'warteliste', 'aktiv'], array_values($this->statuses()));
        $this->assertSame([null, 'B2', null], array_values(DynamicRecord::forTable($this->enrolments)->newQuery()->orderBy('id')->pluck('bemerkung')->all()));
        $this->assertSame(1, WorkItem::query()->where('type', BulkRecordsWork::KEY)->count());
    }

    public function test_check_only_changes_nothing_and_keeps_the_dialog_open(): void
    {
        $ids = $this->enrol(2);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->mountAction($this->bulkEdit())
            ->fillForm(['changes' => ['status' => ['on' => true, 'op' => 'set', 'status' => 'aktiv']]])
            ->callMountedAction(['dry_run' => true])
            ->assertNotified('Geprüft: alle 2 Datensätze bestehen die Prüfungen und würden geändert.')
            ->assertActionMounted($this->bulkEdit());

        $this->assertSame(['warteliste', 'warteliste'], array_values($this->statuses()));
    }

    public function test_a_failing_check_is_reported_and_nothing_changes(): void
    {
        $ids = $this->enrol(2);
        Event::listen(RecordSaving::class, static function (RecordSaving $event): void {
            if ($event->record->getAttribute('name') === 'Person 2') {
                throw new RecordRejected('Kurs ist voll.');
            }
        });

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->callAction($this->bulkEdit(), ['changes' => ['status' => ['on' => true, 'op' => 'set', 'status' => 'aktiv']]])
            ->assertNotified('Nichts geändert: 1 Datensatz besteht die Prüfungen nicht.');

        $this->assertSame(['warteliste', 'warteliste'], array_values($this->statuses()));
    }

    public function test_nothing_ticked_is_refused(): void
    {
        $ids = $this->enrol(1);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->callAction($this->bulkEdit(), [])
            ->assertNotified('Wählen Sie mindestens ein Feld, das geändert werden soll.');

        $this->assertSame(0, WorkItem::query()->count());
    }

    public function test_limit_is_checked_before_the_work_starts(): void
    {
        config(['dynamic_data.bulk_max' => 1]);
        $ids = $this->enrol(2);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->callAction($this->bulkEdit(), ['changes' => ['bemerkung' => ['on' => true, 'op' => 'clear']]])
            ->assertNotified('2 Datensätze ausgewählt; höchstens 1 sind je Sammeländerung möglich. Bitte den Filter eingrenzen.');

        $this->assertSame(0, WorkItem::query()->count());
    }

    public function test_a_bulk_edit_not_finished_in_the_request_continues_as_work(): void
    {
        app()->instance(TimeLimit::class, new class extends TimeLimit
        {
            public function budget(float $wanted, int $margin = 5): float
            {
                return 0.0;
            }
        });
        $ids = $this->enrol(2);

        $component = Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->callAction($this->bulkEdit(), ['changes' => ['bemerkung' => ['on' => true, 'op' => 'clear']]]);
        $item = WorkItem::query()->where('type', BulkRecordsWork::KEY)->sole();

        $component->assertRedirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));
    }

    public function test_hidden_without_switch_or_permission(): void
    {
        $this->enrol(1);
        $this->service()->updateTable($this->enrolments, ['bulk_enabled' => false]);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])->assertActionHidden($this->bulkEdit());

        $this->service()->updateTable($this->enrolments->refresh(), ['bulk_enabled' => true]);
        $this->actingAs($this->userWithPermissions(['data.anmeldungen.view']));
        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])->assertActionHidden($this->bulkEdit());

        $this->actingAs($this->userWithPermissions(['data.anmeldungen.view', 'data.anmeldungen.update']));
        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])->assertActionVisible($this->bulkEdit());
    }

    public function test_history_marks_bulk_changes(): void
    {
        $ids = $this->enrol(1);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->callAction($this->bulkEdit(), ['changes' => ['status' => ['on' => true, 'op' => 'set', 'status' => 'aktiv']]]);

        $rows = DynamicRecordActions::historyRows(DynamicRecord::forTable($this->enrolments)->newQuery()->findOrFail($ids[0]));
        $this->assertStringContainsString('(Sammeländerung)', $rows[0]['what']);
    }

    public function test_detail_table_offers_the_bulk_edit_without_the_master_relation(): void
    {
        $course = app(RecordWriter::class)->create($this->courses, ['titel' => 'Klavier'], WriteOptions::seed());
        $ids = $this->enrol(2, (int) $course->getKey());
        $other = app(RecordWriter::class)->create($this->courses, ['titel' => 'Gitarre'], WriteOptions::seed());

        $detail = Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'kurse',
            'parentId' => $course->getKey(),
            'relationFieldId' => $this->fieldId('anmeldungen', 'kurs_id'),
            'level' => 1,
        ]);

        $detail->selectTableRecords($ids)
            ->mountAction($this->bulkEdit())
            ->fillForm(['changes' => ['kurs_id' => ['on' => true, 'op' => 'set', 'kurs_id' => $other->getKey()], 'status' => ['on' => true, 'op' => 'set', 'status' => 'aktiv']]])
            ->callMountedAction()
            ->assertNotified('2 Datensätze geändert.');

        $this->assertSame(['aktiv', 'aktiv'], array_values($this->statuses()));
        $this->assertSame([(int) $course->getKey()], array_values(array_unique(array_map('intval', DynamicRecord::forTable($this->enrolments)->newQuery()->pluck('kurs_id')->all()))), 'Die Verknüpfung zur Haupttabelle bleibt.');
    }

    public function test_bulk_delete_skips_blocked_records_and_restore_brings_them_back(): void
    {
        $course = app(RecordWriter::class)->create($this->courses, ['titel' => 'Klavier'], WriteOptions::seed());
        $empty = app(RecordWriter::class)->create($this->courses, ['titel' => 'Leer'], WriteOptions::seed());
        $this->enrol(1, (int) $course->getKey());

        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->selectTableRecords([(string) $course->getKey(), (string) $empty->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified();

        $this->assertNull(DynamicRecord::forTable($this->courses)->newQuery()->find($empty->getKey()));
        $this->assertNotNull(DynamicRecord::forTable($this->courses)->newQuery()->find($course->getKey()), 'Mit Anmeldungen: gesperrt.');
        $run = Activity::query()->where('log_name', 'dynamic.kurse')->where('event', 'deleted')->sole()->properties['run'] ?? '';
        $this->assertStringStartsWith('bulk:', (string) $run);

        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->filterTable('trashed', false)
            ->selectTableRecords([(string) $empty->getKey()])
            ->callAction(TestAction::make('restore')->table()->bulk());

        $this->assertNotNull(DynamicRecord::forTable($this->courses)->newQuery()->find($empty->getKey()));
    }

    public function test_preview_shows_examples_before_and_after(): void
    {
        $ids = $this->enrol(12);
        $state = ['status' => ['on' => true, 'op' => 'set', 'status' => 'aktiv'], 'bemerkung' => ['on' => false]];

        $html = view('filament.data.bulk-preview', BulkRecordActions::previewData($this->enrolments, [], $state, $ids))->render();

        $this->assertStringContainsString('12 Datensätze werden geändert', $html);
        $this->assertStringContainsString('<span class="before">Warteliste</span> → <strong>Aktiv</strong>', $html);
        $this->assertStringContainsString('… und 2 weitere.', $html);
        $this->assertStringNotContainsString('Bemerkung', $html, 'Nur angehakte Felder.');

        $empty = view('filament.data.bulk-preview', BulkRecordActions::previewData($this->enrolments, [], [], $ids))->render();
        $this->assertStringContainsString('Noch kein Feld angehakt.', $empty);

        config(['dynamic_data.bulk_max' => 5]);
        $tooMany = view('filament.data.bulk-preview', BulkRecordActions::previewData($this->enrolments, [], $state, $ids))->render();
        $this->assertStringContainsString('höchstens 5', $tooMany);
    }

    /** @return list<string> */
    private function enrolWith(array $rows): array
    {
        $writer = app(RecordWriter::class);

        return array_map(fn (array $row, int $i): string => (string) $writer->create($this->enrolments, [
            'name' => "Person {$i}", 'status' => 'warteliste', ...$row,
        ], WriteOptions::seed())->getKey(), $rows, array_keys($rows));
    }

    public function test_calculates_and_replaces_through_the_dialog(): void
    {
        $ids = $this->enrolWith([['tarif' => '12.34', 'bemerkung' => 'Hauptstr. 5'], ['tarif' => '100', 'bemerkung' => 'Str. des 1. Mai'], ['tarif' => null, 'bemerkung' => null]]);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->mountAction($this->bulkEdit())
            ->fillForm(['changes' => [
                'tarif' => ['on' => true, 'op' => 'calc', 'operator' => 'percent', 'operand' => '5', 'round_mode' => 'multiple', 'round_value' => '0,05'],
                'bemerkung' => ['on' => true, 'op' => 'replace', 'search' => 'str.', 'replace' => 'strasse', 'keep' => true],
            ]])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('2 Datensätze geändert. 1 ohne Wert bzw. ohne Treffer (unverändert).');

        $records = DynamicRecord::forTable($this->enrolments)->newQuery()->orderBy('id')->get();
        $this->assertSame(['12.95', '105.00', null], $records->pluck('tarif')->all());
        $this->assertSame(['Hauptstrasse 5', 'Strasse des 1. Mai', null], $records->pluck('bemerkung')->all(), 'Schreibweise übernommen.');
    }

    public function test_formula_through_the_dialog_and_an_invalid_formula_is_refused(): void
    {
        $ids = $this->enrolWith([['tarif' => '10'], ['tarif' => '20']]);

        Livewire::test(DynamicDataPage::class, ['table' => 'anmeldungen'])
            ->selectTableRecords($ids)
            ->mountAction($this->bulkEdit())
            ->fillForm(['changes' => ['tarif' => ['on' => true, 'op' => 'formula', 'formula' => 'tarif * name']]])
            ->callMountedAction()
            ->assertNotified()
            ->fillForm(['changes' => ['tarif' => ['on' => true, 'op' => 'formula', 'formula' => 'tarif * 2 + 1']]])
            ->callMountedAction()
            ->assertNotified('2 Datensätze geändert.');

        $this->assertSame(['21.00', '41.00'], DynamicRecord::forTable($this->enrolments)->newQuery()->orderBy('id')->pluck('tarif')->all());
    }

    public function test_preview_counts_unchanged_records_and_shows_errors_and_invalid_input(): void
    {
        $ids = $this->enrolWith([['bemerkung' => 'Hauptstr. 5'], ['bemerkung' => 'Weg'], ['bemerkung' => null], ['tarif' => '5']]);
        $render = fn (array $state): string => view('filament.data.bulk-preview', BulkRecordActions::previewData($this->enrolments, [], $state, $ids))->render();

        $html = $render(['bemerkung' => ['on' => true, 'op' => 'replace', 'search' => 'str.', 'replace' => 'strasse']]);
        $this->assertStringContainsString('1 Datensatz wird geändert', $html);
        $this->assertStringContainsString('3 ohne Wert bzw. ohne Treffer bleiben unverändert.', $html);
        $this->assertStringContainsString('<span class="before">Hauptstr. 5</span> → <strong>Hauptstrasse 5</strong>', $html);
        $this->assertStringNotContainsString('>Weg<', $html, 'Nur Beispiele, die sich ändern.');

        $html = $render(['tarif' => ['on' => true, 'op' => 'formula', 'formula' => 'tarif / (tarif - 5)']]);
        $this->assertStringContainsString('Bei 1 Datensatz scheitert die Rechnung', $html);
        $this->assertStringContainsString('data-bulk-error>Division durch 0.', $html);

        $html = $render(['tarif' => ['on' => true, 'op' => 'calc', 'operator' => 'add', 'operand' => '']]);
        $this->assertStringContainsString('„Tarif“: Geben Sie eine Zahl ein.', $html);

        $html = $render(['bemerkung' => ['on' => true, 'op' => 'replace', 'search' => 'xyz', 'replace' => 'abc']]);
        $this->assertStringContainsString('Bei keinem Datensatz ändert sich etwas.', $html);
    }
}
