<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Bulk\BulkChange;
use App\DynamicData\Bulk\BulkPreview;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Models\WorkItem;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;
use App\Work\Types\BulkRecordsWork;
use App\Work\WorkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** Sammeländerung (B-133, S1): Arbeit „bulk_records“ mit Prüf- und Schreibphase, alles oder nichts. */
class BulkEditTest extends DynamicDataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 10:00:00');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
    }

    private function enrolments(): CustomTable
    {
        return $this->service()->createTable(['name' => 'anmeldungen', 'label' => 'Anmeldungen'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true, 'show_in_table' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                ['value' => 'warteliste'], ['value' => 'aktiv'], ['value' => 'abgemeldet'],
            ]]],
            ['name' => 'bemerkung', 'label' => 'Bemerkung', 'type' => 'string'],
            ['name' => 'kuerzel', 'label' => 'Kürzel', 'type' => 'string', 'is_unique' => true],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal'],
            ['name' => 'doppelt', 'label' => 'Doppelt', 'type' => 'computed', 'options' => ['formula' => 'tarif * 2']],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [
                NumberPattern::PATTERN => 'A-{0000}', NumberCondition::OPTION => "status = 'aktiv'",
            ]],
        ]);
    }

    /** @return list<int> */
    private function seedRecords(CustomTable $table, int $count, string $status = 'warteliste'): array
    {
        $writer = app(RecordWriter::class);

        return $writer->batch(fn (): array => array_map(
            static fn (int $i): int => (int) $writer->create($table, ['name' => "Person {$i}", 'status' => $status, 'bemerkung' => "B{$i}", 'tarif' => '10'], WriteOptions::seed())->getKey(),
            range(1, $count),
        ));
    }

    /**
     * @param  array<string, array{op: string, value?: mixed}>  $changes
     * @param  list<int>  $ids
     */
    private function bulk(CustomTable $table, array $changes, array $ids, bool $dryRun = false, array $locked = []): WorkItem
    {
        return app(WorkService::class)->startAndDrive(BulkRecordsWork::KEY, [
            'table_id' => $table->id,
            'changes' => $changes,
            'locked' => $locked,
            'record_ids' => $ids,
            'dry_run' => $dryRun,
        ], auth()->user());
    }

    /** @return array<int, mixed> */
    private function column(string $field): array
    {
        return DynamicRecord::forTableName('anmeldungen')->newQuery()->orderBy('id')->pluck($field, 'id')->all();
    }

    public function test_sets_and_clears_fields_writes_one_log_entry_per_record_and_skips_unchanged(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, 3);
        app(RecordWriter::class)->update(DynamicRecord::forTable($table)->newQuery()->find($ids[2]), ['status' => 'aktiv', 'bemerkung' => null], WriteOptions::seed());

        $item = $this->bulk($table, ['status' => ['op' => 'set', 'value' => 'aktiv'], 'bemerkung' => ['op' => 'clear']], $ids);

        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertStringContainsString('2 Datensätze geändert', (string) $item->message);
        $this->assertStringContainsString('1 war schon so', (string) $item->message);
        $this->assertSame(['aktiv', 'aktiv', 'aktiv'], array_values($this->column('status')));
        $this->assertSame([null, null, null], array_values($this->column('bemerkung')));

        $run = (string) $item->result['run'];
        $this->assertStringStartsWith('bulk:', $run);
        $logged = Activity::query()->where('log_name', 'dynamic.anmeldungen')->get()->filter(static fn (Activity $a): bool => ($a->properties['run'] ?? null) === $run);
        $this->assertCount(2, $logged);
        $this->assertSame(['bulk'], $logged->map(static fn (Activity $a): mixed => $a->properties['via'] ?? null)->unique()->values()->all());
        $this->assertSame($this->admin->id, $logged->first()->causer_id);
    }

    public function test_assign_when_numbers_records_in_key_order_and_the_check_phase_leaves_no_counter(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, 3);

        $dry = $this->bulk($table, ['status' => ['op' => 'set', 'value' => 'aktiv']], $ids, dryRun: true);
        $this->assertSame(WorkItem::DONE, $dry->status, (string) $dry->message);
        $this->assertStringContainsString('alle 3 Datensätze bestehen', (string) $dry->message);
        $this->assertSame([null, null, null], array_values($this->column('nummer')));
        $this->assertSame(0, Activity::query()->where('log_name', 'dynamic.anmeldungen')->count(), 'Der Probelauf hinterlässt kein Protokoll.');

        $item = $this->bulk($table, ['status' => ['op' => 'set', 'value' => 'aktiv']], array_reverse($ids));

        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertSame(['A-0001', 'A-0002', 'A-0003'], array_values($this->column('nummer')), 'Zähler unberührt vom Probelauf, Vergabe nach Schlüssel.');
    }

    public function test_a_failing_check_changes_nothing_and_lists_the_records(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, 3);
        Event::listen(RecordSaving::class, static function (RecordSaving $event): void {
            if ($event->record->getAttribute('name') === 'Person 2') {
                throw new RecordRejected('Lehrperson hat zu dieser Zeit schon einen Kurs.');
            }
        });

        $item = $this->bulk($table, ['status' => ['op' => 'set', 'value' => 'aktiv']], $ids);

        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('Nichts geändert: 1 Datensatz', (string) $item->message);
        $this->assertStringContainsString('„Person 2“: Lehrperson hat zu dieser Zeit schon einen Kurs.', (string) $item->message);
        $this->assertSame(['warteliste', 'warteliste', 'warteliste'], array_values($this->column('status')));
        $this->assertSame([null, null, null], array_values($this->column('nummer')));
        $this->assertSame(0, Activity::query()->where('log_name', 'dynamic.anmeldungen')->count());
    }

    public function test_the_check_uses_the_field_checks_of_data_entry(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, 2);

        $item = $this->bulk($table, ['status' => ['op' => 'set', 'value' => 'unbekannt']], $ids);

        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('2 Datensätze bestehen die Prüfungen nicht', (string) $item->message);
        $this->assertSame(['warteliste', 'warteliste'], array_values($this->column('status')));
    }

    public function test_a_failure_in_the_write_phase_reverts_the_finished_blocks(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, BulkRecordsWork::BLOCK + 20);
        $events = 0;
        $limit = count($ids) + BulkRecordsWork::BLOCK + 10;
        Event::listen(RecordSaving::class, static function () use (&$events, $limit): void {
            // Prüfphase: ein Ereignis je Datensatz; danach Schreibphase, zweiter Block.
            if (++$events === $limit) {
                throw new RecordRejected('Plötzlich nicht mehr erlaubt.');
            }
        });

        $item = $this->bulk($table, ['bemerkung' => ['op' => 'set', 'value' => 'neu']], $ids);

        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('Plötzlich nicht mehr erlaubt. Die Sammeländerung wurde vollständig zurückgenommen.', (string) $item->message);
        $this->assertSame(array_map(static fn (int $i): string => "B{$i}", range(1, count($ids))), array_values($this->column('bemerkung')));
        // Erster Block geschrieben und zurückgeschrieben: je Datensatz zwei Einträge mit derselben Kennung.
        $this->assertSame(2 * BulkRecordsWork::BLOCK, Activity::query()->where('log_name', 'dynamic.anmeldungen')->count());
    }

    public function test_a_record_changed_between_check_and_write_reverts_everything(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, BulkRecordsWork::BLOCK + 20);
        $events = 0;
        $trigger = count($ids) + BulkRecordsWork::BLOCK + 1;
        $victim = $ids[BulkRecordsWork::BLOCK + 10];
        Event::listen(RecordSaving::class, static function () use (&$events, $trigger, $victim): void {
            if (++$events === $trigger) {
                // Jemand anders speichert während der Schreibphase einen Datensatz des Blocks.
                DB::connection('app_data')->table(app(DynamicDataNamespace::class)->qualify('anmeldungen'))->where('id', $victim)->update(['bemerkung' => 'fremd']);
            }
        });

        $item = $this->bulk($table, ['bemerkung' => ['op' => 'set', 'value' => 'neu']], $ids);

        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('inzwischen von jemand anderem geändert', (string) $item->message);
        $this->assertNotContains('neu', $this->column('bemerkung'));
    }

    public function test_rejects_fields_that_a_bulk_edit_must_not_change(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, 2);

        foreach ([
            'nummer' => ['op' => 'set', 'value' => 'A-9999'],
            'doppelt' => ['op' => 'set', 'value' => '1'],
            'kuerzel' => ['op' => 'set', 'value' => 'X'],
            'status' => ['op' => 'clear'],
        ] as $field => $change) {
            $item = $this->bulk($table, [$field => $change], $ids);
            $this->assertSame(WorkItem::FAILED, $item->status, $field);
        }

        $this->assertSame(WorkItem::DONE, $this->bulk($table, ['kuerzel' => ['op' => 'clear']], $ids)->status, 'Eindeutige Felder lassen sich leeren.');
        $this->assertSame(WorkItem::FAILED, $this->bulk($table, ['name' => ['op' => 'set', 'value' => 'X']], $ids, locked: ['name'])->status, 'Gesperrte Felder nicht.');
    }

    public function test_editable_fields_follow_the_concept(): void
    {
        $table = $this->enrolments();

        $this->assertSame(['name', 'status', 'bemerkung', 'kuerzel', 'tarif'], array_keys(BulkChange::editableFields($table)));
        $this->assertSame(['status', 'bemerkung', 'kuerzel', 'tarif'], array_keys(BulkChange::editableFields($table, ['name'])));
    }

    public function test_needs_the_table_switch_and_the_edit_permission(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, 1);

        $this->service()->updateTable($table, ['bulk_enabled' => false]);
        $this->assertFalse(BulkChange::allowed($table->refresh()));
        $this->assertSame(WorkItem::FAILED, $this->bulk($table, ['bemerkung' => ['op' => 'clear']], $ids)->status);

        $this->service()->updateTable($table, ['bulk_enabled' => true]);
        $this->actingAs($this->userWithPermissions(['data.anmeldungen.view']));
        $this->assertFalse(BulkChange::allowed($table->refresh()));
        $this->assertSame(WorkItem::FAILED, $this->bulk($table, ['bemerkung' => ['op' => 'clear']], $ids)->status);

        $this->actingAs($this->userWithPermissions(['data.anmeldungen.view', 'data.anmeldungen.update']));
        $this->assertTrue(BulkChange::allowed($table));
    }

    public function test_validation_messages(): void
    {
        $table = $this->enrolments();

        foreach ([[[], 'bulk.no_changes'], [['status' => ['op' => 'set', 'value' => '']], 'bulk.required'], [['nummer' => ['op' => 'clear']], 'bulk.not_editable']] as [$changes, $code]) {
            try {
                BulkChange::fromArray($table, $changes)->validate();
                $this->fail("{$code} erwartet.");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    public function test_preview_shows_examples_and_number_hints(): void
    {
        $table = $this->enrolments();
        $ids = $this->seedRecords($table, 12);
        $this->service()->updateTable($table, ['label' => 'Anmeldungen']);
        app(RecordWriter::class)->update(DynamicRecord::forTable($table)->newQuery()->find($ids[0]), ['status' => 'aktiv'], WriteOptions::ui());

        $preview = BulkPreview::for(BulkChange::fromArray($table->refresh(), ['status' => ['op' => 'set', 'value' => 'abgemeldet']]), array_reverse($ids));

        $this->assertSame(12, $preview->count);
        $this->assertCount(BulkPreview::EXAMPLES, $preview->examples);
        $this->assertSame($ids[0], (int) $preview->examples[0]['record']->getKey());
        $this->assertSame(['status' => 'aktiv'], $preview->examples[0]['before']);
        $this->assertSame(['status' => 'abgemeldet'], $preview->examples[0]['after']);
        $this->assertSame('aktiv', DynamicRecord::forTable($table)->newQuery()->find($ids[0])->status, 'Die Vorschau schreibt nichts.');
        $this->assertCount(1, $preview->hints);
        $this->assertStringContainsString('„Vergeben, wenn“', $preview->hints[0]);
    }
}
