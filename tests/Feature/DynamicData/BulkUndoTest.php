<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Bulk\BulkChangeAccess;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Models\Activity;
use App\Models\BulkChange;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Models\WorkItem;
use App\Tasks\Types\ActivityPruneTask;
use App\Work\Types\BulkRecordsWork;
use App\Work\WorkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Sammeländerung S3 „Rückgängig“ (B-133, BULK_EDIT.md §13): Kopf je Sammelaktion, Rücknahme aus dem Protokoll. */
class BulkUndoTest extends DynamicDataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 10:00:00');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
    }

    private function courses(): CustomTable
    {
        return $this->service()->createTable(['name' => 'kurse', 'label' => 'Kurse'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true, 'show_in_table' => true],
            ['name' => 'raum', 'label' => 'Raum', 'type' => 'string'],
            ['name' => 'kuerzel', 'label' => 'Kürzel', 'type' => 'string', 'is_unique' => true],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal'],
            ['name' => 'doppelt', 'label' => 'Doppelt', 'type' => 'computed', 'options' => ['formula' => 'tarif * 2']],
            ['name' => 'beginn', 'label' => 'Beginn', 'type' => 'date'],
            ['name' => 'termin', 'label' => 'Termin', 'type' => 'datetime'],
            ['name' => 'aktiv', 'label' => 'Aktiv', 'type' => 'boolean'],
        ]);
    }

    /** @return list<int> */
    private function seedCourses(CustomTable $table, int $count): array
    {
        $writer = app(RecordWriter::class);

        return $writer->batch(fn (): array => array_map(
            static fn (int $i): int => (int) $writer->create($table, [
                'name' => "Kurs {$i}", 'raum' => "R{$i}", 'kuerzel' => "K{$i}", 'tarif' => '120.00',
                'beginn' => '2026-09-01', 'termin' => '2026-09-01 18:30:00', 'aktiv' => true,
            ], WriteOptions::seed())->getKey(),
            range(1, $count),
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes
     * @param  list<int>  $ids
     */
    private function bulk(CustomTable $table, array $changes, array $ids, bool $dryRun = false): WorkItem
    {
        return app(WorkService::class)->startAndDrive(BulkRecordsWork::KEY, [
            'table_id' => $table->id, 'changes' => $changes, 'locked' => [], 'record_ids' => $ids, 'dry_run' => $dryRun,
        ], auth()->user());
    }

    private function undo(BulkChange $head): WorkItem
    {
        return app(WorkService::class)->startAndDrive(BulkRecordsWork::KEY, [
            'table_id' => $head->custom_table_id, 'changes' => $head->field_changes ?? [], 'locked' => [],
            'record_ids' => $head->recordIds(), 'dry_run' => false, 'undo_of' => $head->id,
        ], auth()->user());
    }

    private function headOf(WorkItem $item): BulkChange
    {
        return BulkChange::query()->where('run', (string) $item->result['run'])->firstOrFail();
    }

    /** @return array<int, mixed> rohe Werte der Spalte */
    private function column(string $field): array
    {
        return DynamicRecord::forTableName('kurse')->newQuery()->withTrashed()->orderBy('id')->get()
            ->mapWithKeys(static fn (DynamicRecord $r): array => [$r->getKey() => $r->getRawOriginal($field)])->all();
    }

    private function record(int $id): DynamicRecord
    {
        return DynamicRecord::forTableName('kurse')->newQuery()->withTrashed()->findOrFail($id);
    }

    private const array PERCENT = ['tarif' => ['op' => 'calc', 'operator' => 'percent', 'operand' => '5', 'round_mode' => 'multiple', 'round_value' => '0.05']];

    public function test_undo_restores_old_values_records_a_head_and_marks_the_original(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 3);

        $item = $this->bulk($table, self::PERCENT, $ids);
        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $head = $this->headOf($item);
        $this->assertSame([BulkChange::UPDATE, 3, $this->admin->id, $table->app_id], [$head->operation, $head->count, $head->user_id, $head->app_id]);
        $this->assertSame($ids, $head->recordIds());
        $this->assertSame('percent', $head->field_changes['tarif']['operator']);
        $this->assertSame(['126.00', '126.00', '126.00'], array_values($this->column('tarif')));

        $undo = $this->undo($head);

        $this->assertSame(WorkItem::DONE, $undo->status, (string) $undo->message);
        $this->assertStringContainsString('3 Datensätze zurückgenommen.', (string) $undo->message);
        $this->assertSame(['120.00', '120.00', '120.00'], array_values($this->column('tarif')));
        $this->assertEquals([240, 240, 240], array_map('floatval', array_values($this->column('doppelt'))), 'Berechnetes Feld neu gerechnet.');

        $back = $this->headOf($undo);
        $this->assertSame([BulkChange::UPDATE, $head->id, 3], [$back->operation, $back->undo_of_id, $back->count]);
        $this->assertSame($back->id, $head->fresh()->undone_by_id);
        $this->assertNotSame($head->run, $back->run);
        $this->assertFalse(BulkChangeAccess::undoable($head->fresh()), 'Ganz zurückgenommen: kein zweites Mal.');

        $entries = Activity::query()->where('log_name', 'dynamic.kurse')->get()->filter(static fn (Activity $a): bool => ($a->properties['run'] ?? null) === $back->run);
        $this->assertCount(3, $entries);
        $this->assertSame(['bulk'], $entries->map(static fn (Activity $a): mixed => $a->properties['via'] ?? null)->unique()->values()->all());
    }

    public function test_a_record_changed_since_in_a_bulk_field_stays_and_other_fields_do_not_count(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 3);
        $head = $this->headOf($this->bulk($table, self::PERCENT, $ids));
        app(RecordWriter::class)->update($this->record($ids[1]), ['tarif' => '130.00']);
        app(RecordWriter::class)->update($this->record($ids[2]), ['raum' => 'Saal']);

        $undo = $this->undo($head);

        $this->assertSame(WorkItem::DONE, $undo->status, (string) $undo->message);
        $this->assertStringContainsString('2 Datensätze zurückgenommen. 1 seither geändert, bleibt.', (string) $undo->message);
        $this->assertStringContainsString('#'.$ids[1].' „Kurs 2“: seither geändert', (string) $undo->message);
        $this->assertSame(['120.00', '130.00', '120.00'], array_values($this->column('tarif')));
        $this->assertSame('Saal', $this->column('raum')[$ids[2]]);

        $back = $this->headOf($undo);
        $this->assertSame([$ids[0], $ids[2]], $back->recordIds());
        $this->assertTrue(BulkChangeAccess::undoable($head->fresh()), 'Teilweise zurückgenommen: erneut möglich.');

        // Nach dem Bereinigen erneut: der eine kommt zurück, die anderen sind schon zurück.
        app(RecordWriter::class)->update($this->record($ids[1]), ['tarif' => '126.00']);
        $again = $this->undo($head->fresh());
        $this->assertStringContainsString('1 Datensatz zurückgenommen. 2 waren schon zurück.', (string) $again->message);
        $this->assertSame(['120.00', '120.00', '120.00'], array_values($this->column('tarif')));
    }

    public function test_undo_of_an_undo_brings_the_bulk_values_back(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 2);
        $head = $this->headOf($this->bulk($table, ['raum' => ['op' => 'set', 'value' => 'Aula']], $ids));
        $back = $this->headOf($this->undo($head));

        $redo = $this->undo($back);

        $this->assertSame(WorkItem::DONE, $redo->status, (string) $redo->message);
        $this->assertSame(['Aula', 'Aula'], array_values($this->column('raum')));
        $this->assertSame($back->id, $this->headOf($redo)->undo_of_id);
    }

    public function test_an_old_value_that_is_no_longer_valid_is_skipped_with_the_reason(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 2);
        $head = $this->headOf($this->bulk($table, ['kuerzel' => ['op' => 'clear']], $ids));
        app(RecordWriter::class)->create($table, ['name' => 'Neu', 'kuerzel' => 'K1']);

        $undo = $this->undo($head);

        $this->assertSame(WorkItem::DONE, $undo->status, (string) $undo->message);
        $this->assertStringContainsString('1 Datensatz zurückgenommen. 1 besteht die Prüfung nicht, bleibt.', (string) $undo->message);
        $this->assertStringContainsString('„Kurs 1“: Kürzel:', (string) $undo->message);
        $this->assertSame([null, 'K2'], [$this->column('kuerzel')[$ids[0]], $this->column('kuerzel')[$ids[1]]]);
    }

    public function test_dates_come_back_exactly_also_from_entries_logged_in_utc(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 2);
        $head = $this->headOf($this->bulk($table, [
            'beginn' => ['op' => 'calc', 'operator' => 'add_days', 'operand' => '30'],
            'termin' => ['op' => 'calc', 'operator' => 'add_months', 'operand' => '1'],
            'aktiv' => ['op' => 'set', 'value' => false],
        ], $ids));

        // Einträge von vor 0.8.1: neuer Wert eines Datums in UTC (Spatie über serializeDate()).
        $entry = Activity::query()->where('subject_id', $ids[1])->where('properties->run', $head->run)->firstOrFail();
        $changes = $entry->attribute_changes->all();
        $this->assertSame('2026-10-01', substr((string) $changes['attributes']['beginn'], 0, 10), 'Rohwert im Protokoll.');
        $changes['attributes']['beginn'] = '2026-09-30T22:00:00.000000Z';
        $changes['attributes']['termin'] = '2026-10-01T16:30:00.000000Z';
        $entry->forceFill(['attribute_changes' => collect($changes)])->save();

        $undo = $this->undo($head);

        $this->assertStringContainsString('2 Datensätze zurückgenommen.', (string) $undo->message);
        foreach ($ids as $id) {
            $record = $this->record($id);
            $this->assertSame('2026-09-01', $record->getAttribute('beginn')->format('Y-m-d'));
            $this->assertSame('2026-09-01 18:30:00', $record->getAttribute('termin')->format('Y-m-d H:i:s'));
            $this->assertTrue($record->getAttribute('aktiv'));
        }
    }

    public function test_the_log_holds_raw_values_and_no_false_changes_of_dates(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 1);
        $record = $this->record($ids[0]);

        app(RecordWriter::class)->update($record, ['raum' => 'Saal', 'beginn' => '2026-09-01', 'tarif' => '120']);

        $entry = Activity::query()->where('subject_id', $ids[0])->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['raum' => 'Saal'], $entry->attribute_changes->get('attributes'), 'Gleiches Datum bzw. gleicher Betrag in anderer Schreibweise ist keine Änderung.');

        app(RecordWriter::class)->update($record, ['beginn' => '2026-09-15', 'termin' => '2026-09-15 08:00']);
        $entry = Activity::query()->where('subject_id', $ids[0])->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('2026-09-01', substr((string) $entry->attribute_changes->get('old')['beginn'], 0, 10));
        $this->assertStringStartsWith('2026-09-15', (string) $entry->attribute_changes->get('attributes')['beginn']);
        $this->assertStringNotContainsString('T', (string) $entry->attribute_changes->get('attributes')['termin']);
        $this->assertSame('2026-09-15 08:00:00', $entry->attribute_changes->get('attributes')['termin']);
    }

    public function test_bulk_delete_and_restore_can_be_undone(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 3);
        $run = BulkRecordsWork::RUN_PREFIX.Str::uuid();
        foreach ($ids as $id) {
            app(RecordWriter::class)->delete($this->record($id), WriteOptions::bulk($run));
        }
        $deleted = BulkChange::recordRun($run, $table, BulkChange::DELETE, $ids);
        app(RecordWriter::class)->restore($this->record($ids[2]));

        $undo = $this->undo($deleted);

        $this->assertSame(WorkItem::DONE, $undo->status, (string) $undo->message);
        $this->assertStringContainsString('2 Datensätze zurückgenommen. 1 war schon zurück.', (string) $undo->message);
        $this->assertSame([null, null, null], array_values($this->column('deleted_at')));
        $restore = $this->headOf($undo);
        $this->assertSame([BulkChange::RESTORE, [$ids[0], $ids[1]]], [$restore->operation, $restore->recordIds()]);

        $again = $this->undo($restore);

        $this->assertStringContainsString('2 Datensätze zurückgenommen.', (string) $again->message);
        $this->assertNotNull($this->column('deleted_at')[$ids[0]]);
        $this->assertNull($this->column('deleted_at')[$ids[2]]);
    }

    public function test_no_head_for_a_check_only_or_a_failed_bulk_edit(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 2);

        $this->assertSame(WorkItem::DONE, $this->bulk($table, self::PERCENT, $ids, dryRun: true)->status);
        $this->assertSame(WorkItem::FAILED, $this->bulk($table, ['kuerzel' => ['op' => 'set', 'value' => 'X']], $ids)->status);

        $this->assertSame(0, BulkChange::query()->count());
    }

    public function test_own_bulk_edits_for_editors_others_only_with_the_change_log_right(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 2);
        $editor = $this->userWithPermissions(['data.kurse.view', 'data.kurse.update']);
        $auditor = $this->userWithPermissions(['data.kurse.view', 'data.kurse.update', 'activity_log.view']);
        $own = $this->headOf($this->bulk($table, ['raum' => ['op' => 'set', 'value' => 'Aula']], $ids));

        $this->actingAs($editor);
        $mine = $this->headOf($this->bulk($table, ['raum' => ['op' => 'set', 'value' => 'Saal']], $ids));

        $this->assertSame([$mine->id], BulkChangeAccess::recent($editor, $table)->pluck('id')->all());
        $this->assertTrue(BulkChangeAccess::canUndo($editor, $mine));
        $this->assertFalse(BulkChangeAccess::canUndo($editor, $own));
        $this->assertSame([$mine->id, $own->id], BulkChangeAccess::recent($auditor, $table)->pluck('id')->all());
        $this->assertTrue(BulkChangeAccess::canUndo($auditor, $own));
        $this->assertSame([], BulkChangeAccess::recent($this->userWithPermissions(['data.kurse.view']), $table)->all());

        $refused = $this->undo($own);
        $this->assertSame(WorkItem::FAILED, $refused->status);
        $this->assertStringContainsString('nicht zurücknehmen', (string) $refused->message);
        $this->assertSame(['Saal', 'Saal'], array_values($this->column('raum')));
    }

    public function test_pruning_the_change_log_removes_heads_with_the_same_age(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, 1);
        $old = $this->headOf($this->bulk($table, ['raum' => ['op' => 'set', 'value' => 'Aula']], $ids));
        Carbon::setTestNow('2027-12-01 10:00:00');
        $recent = $this->headOf($this->bulk($table, ['raum' => ['op' => 'set', 'value' => 'Saal']], $ids));

        app(ActivityPruneTask::class)->run(['days' => 365, 'auth_days' => 90]);

        $this->assertSame([$recent->id], BulkChange::query()->pluck('id')->all());
        $this->assertNull(BulkChange::query()->find($old->id));
    }
}
