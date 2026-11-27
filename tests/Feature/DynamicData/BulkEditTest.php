<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Bulk\BulkChange;
use App\DynamicData\Bulk\BulkPreview;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Validation\RecordChecker;
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

/** Sammeländerung (B-133, S1 und S2): Arbeit „bulk_records“ mit Prüf- und Schreibphase, alles oder nichts; rechnen, ersetzen, Formel. */
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

    /** Kurse mit Teilnahmen (Summe über Kind-Datensätze) für S2. */
    private function courses(): CustomTable
    {
        $table = $this->service()->createTable(['name' => 'kurse', 'label' => 'Kurse'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'is_required' => true, 'show_in_table' => true, 'options' => ['length' => 20]],
            ['name' => 'kuerzel', 'label' => 'Kürzel', 'type' => 'string', 'is_unique' => true, 'is_required' => true],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal', 'options' => ['precision' => 6, 'scale' => 2]],
            ['name' => 'plaetze', 'label' => 'Plätze', 'type' => 'integer'],
            ['name' => 'beginn', 'label' => 'Beginn', 'type' => 'date'],
            ['name' => 'termin', 'label' => 'Termin', 'type' => 'datetime'],
            ['name' => 'voll', 'label' => 'Voll', 'type' => 'boolean'],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'text'],
        ]);
        $this->service()->createTable(['name' => 'teilnahmen', 'label' => 'Teilnahmen'], [
            ['name' => 'kurs_id', 'label' => 'Kurs', 'type' => 'relation', 'options' => ['table' => 'kurse', 'display_field' => 'bezeichnung']],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);

        return $table->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function seedCourses(CustomTable $table, array $rows): array
    {
        $writer = app(RecordWriter::class);

        return $writer->batch(fn (): array => array_map(
            static fn (array $row, int $i): int => (int) $writer->create($table, ['bezeichnung' => "Kurs {$i}", 'kuerzel' => "K{$i}", ...$row], WriteOptions::seed())->getKey(),
            $rows,
            array_keys($rows),
        ));
    }

    /** @return array<int, mixed> */
    private function courseColumn(string $field): array
    {
        return DynamicRecord::forTableName('kurse')->newQuery()->orderBy('id')->pluck($field, 'id')->all();
    }

    public function test_calc_raises_by_percent_rounds_to_a_multiple_and_skips_empty_values(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, [['tarif' => '12.34', 'plaetze' => 8], ['tarif' => '100'], ['tarif' => null]]);

        $item = $this->bulk($table, [
            'tarif' => ['op' => 'calc', 'operator' => 'percent', 'operand' => '5', 'round_mode' => 'multiple', 'round_value' => '0.05'],
            'plaetze' => ['op' => 'calc', 'operator' => 'mul', 'operand' => '1,5'],
        ], $ids);

        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertSame(['12.95', '105.00', null], array_values($this->courseColumn('tarif')));
        $this->assertSame([12, null, null], array_values(array_map(static fn (mixed $v): ?int => $v === null ? null : (int) $v, $this->courseColumn('plaetze'))));
        $this->assertStringContainsString('2 Datensätze geändert', (string) $item->message);
        $this->assertStringContainsString('1 ohne Wert bzw. ohne Treffer', (string) $item->message);
        $this->assertSame(1, $item->result['skipped']);
    }

    public function test_calc_shifts_dates_by_days_and_months_and_keeps_the_time(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, [['beginn' => '2026-01-31', 'termin' => '2026-01-31 18:30:00'], ['beginn' => '2026-11-27']]);

        $this->assertSame(WorkItem::DONE, $this->bulk($table, [
            'beginn' => ['op' => 'calc', 'operator' => 'add_days', 'operand' => '30'],
            'termin' => ['op' => 'calc', 'operator' => 'add_months', 'operand' => '1'],
        ], $ids)->status);

        $records = DynamicRecord::forTable($table)->newQuery()->orderBy('id')->get();
        $this->assertSame(['2026-03-02', '2026-12-27'], $records->map(static fn (DynamicRecord $r): string => $r->beginn->format('Y-m-d'))->all());
        $this->assertSame('2026-02-28 18:30:00', $records[0]->termin->format('Y-m-d H:i:s'));
        $this->assertNull($records[1]->termin);
    }

    public function test_replace_changes_only_records_with_a_match_and_checks_the_length(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, [['bezeichnung' => 'Hauptstr. 5'], ['bezeichnung' => 'Str. des 1. Mai'], ['bezeichnung' => 'Bahnhofweg']]);

        $item = $this->bulk($table, ['bezeichnung' => ['op' => 'replace', 'search' => 'Str.', 'replace' => 'Strasse', 'word' => true, 'case' => true]], $ids);

        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertSame(['Hauptstr. 5', 'Strasse des 1. Mai', 'Bahnhofweg'], array_values($this->courseColumn('bezeichnung')));
        $this->assertSame(['changed' => 1, 'skipped' => 2], array_intersect_key($item->result, ['changed' => 0, 'skipped' => 0]));

        $item = $this->bulk($table, ['bezeichnung' => ['op' => 'replace', 'search' => 'str.', 'replace' => 'strasse ganz lang']], $ids);
        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('Höchstens 20 Zeichen', (string) $item->message);
        $this->assertSame('Hauptstr. 5', $this->courseColumn('bezeichnung')[$ids[0]], 'Alles oder nichts.');
    }

    public function test_formula_reads_the_old_value_computed_sums_and_reports_division_by_zero(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, [['tarif' => '10', 'plaetze' => 4], ['tarif' => '20', 'plaetze' => 0], ['tarif' => null, 'plaetze' => 2]]);
        $children = CustomTable::query()->where('name', 'teilnahmen')->firstOrFail();
        $writer = app(RecordWriter::class);
        $writer->create($children, ['kurs_id' => $ids[0], 'betrag' => '7.5'], WriteOptions::seed());
        $writer->create($children, ['kurs_id' => $ids[0], 'betrag' => '2.5'], WriteOptions::seed());

        // Alle Formeln sehen die Werte vor der Änderung: plaetze nutzt den alten Tarif.
        $item = $this->bulk($table, [
            'tarif' => ['op' => 'formula', 'formula' => 'round(tarif * 1.05 / 0.05, 0) * 0.05 + sum(teilnahmen.betrag)'],
            'plaetze' => ['op' => 'formula', 'formula' => 'coalesce(tarif, 0) + plaetze'],
            'voll' => ['op' => 'formula', 'formula' => 'plaetze >= 4'],
        ], $ids);

        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertSame(['20.50', '21.00', null], array_values($this->courseColumn('tarif')));
        $this->assertSame([14, 20, 2], array_values(array_map('intval', $this->courseColumn('plaetze'))));
        $this->assertSame([true, false, false], DynamicRecord::forTable($table)->newQuery()->orderBy('id')->get()->map(static fn (DynamicRecord $r): bool => (bool) $r->voll)->all());

        // Kurs 1 hat jetzt 20 Plätze: Division durch 0.
        $item = $this->bulk($table, ['tarif' => ['op' => 'formula', 'formula' => 'tarif / (plaetze - 20)']], $ids);
        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('Tarif: Division durch 0.', (string) $item->message);
        $this->assertStringContainsString('„Kurs 1“', (string) $item->message);
        $this->assertSame('20.50', $this->courseColumn('tarif')[$ids[0]], 'Nichts geändert.');
    }

    public function test_numbers_that_do_not_fit_the_field_fail_the_check(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, [['tarif' => '9000'], ['tarif' => '10']]);

        $item = $this->bulk($table, ['tarif' => ['op' => 'calc', 'operator' => 'mul', 'operand' => '2']], $ids);

        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('höchstens 4 Stellen vor dem Komma', (string) $item->message);
        $this->assertSame(['9000.00', '10.00'], array_values($this->courseColumn('tarif')));

        $checker = app(RecordChecker::class);
        $this->assertSame([], $checker->check($table, ['tarif' => '9999.99']));
        $this->assertCount(1, $checker->check($table, ['tarif' => '9999.995']), 'Rundet auf 10000.00.');
        $this->assertCount(1, $checker->check($table, ['plaetze' => '9223372036854775808']));
        $this->assertSame([], $checker->check($table, ['plaetze' => '-9223372036854775808']));
    }

    public function test_unique_fields_can_be_replaced_but_duplicates_across_blocks_fail(): void
    {
        $table = $this->courses();
        $rows = array_map(static fn (int $i): array => ['kuerzel' => "A-{$i}"], range(1, BulkRecordsWork::BLOCK + 1));
        $rows[BulkRecordsWork::BLOCK]['kuerzel'] = 'A1-';
        $rows[0]['kuerzel'] = 'A-1';
        $ids = $this->seedCourses($table, $rows);

        // „-“ entfernen: A-1 und A1- ergäben beide A1, im ersten und im zweiten Block.
        $item = $this->bulk($table, ['kuerzel' => ['op' => 'replace', 'search' => '-', 'replace' => '']], $ids);
        $this->assertSame(WorkItem::FAILED, $item->status);
        $this->assertStringContainsString('„A1“ ergäbe sich auch bei #'.$ids[0], (string) $item->message);

        $item = $this->bulk($table, ['kuerzel' => ['op' => 'replace', 'search' => 'A-', 'replace' => 'B-']], $ids);
        $this->assertSame(WorkItem::DONE, $item->status, (string) $item->message);
        $this->assertSame('B-2', $this->courseColumn('kuerzel')[$ids[1]]);
        $this->assertSame(WorkItem::FAILED, $this->bulk($table, ['kuerzel' => ['op' => 'set', 'value' => 'X']], $ids)->status, 'Setzen bleibt verboten.');
    }

    public function test_ops_follow_the_field_type(): void
    {
        $fields = BulkChange::editableFields($this->courses());

        $this->assertSame(['set', 'replace'], BulkChange::ops($fields['bezeichnung']));
        $this->assertSame(['replace'], BulkChange::ops($fields['kuerzel']), 'eindeutig und Pflicht');
        $this->assertSame(['set', 'clear', 'calc', 'formula'], BulkChange::ops($fields['tarif']));
        $this->assertSame(['set', 'clear', 'calc'], BulkChange::ops($fields['beginn']));
        $this->assertSame(['set', 'clear', 'formula'], BulkChange::ops($fields['voll']));
        $this->assertSame(['set', 'clear', 'replace'], BulkChange::ops($fields['notiz']));
    }

    public function test_validation_messages_of_calc_replace_and_formula(): void
    {
        $table = $this->courses();

        foreach ([
            [['tarif' => ['op' => 'calc', 'operator' => 'div', 'operand' => '0']], 'bulk.division_by_zero'],
            [['tarif' => ['op' => 'calc', 'operator' => 'add', 'operand' => 'x']], 'bulk.operand'],
            [['tarif' => ['op' => 'calc', 'operator' => 'add_days', 'operand' => '1']], 'bulk.operator'],
            [['tarif' => ['op' => 'calc', 'operator' => 'add', 'operand' => '1', 'round_mode' => 'multiple', 'round_value' => '0.005']], 'bulk.round_multiple'],
            [['tarif' => ['op' => 'calc', 'operator' => 'add', 'operand' => '1', 'round_mode' => 'digits', 'round_value' => '3']], 'bulk.round_digits'],
            [['beginn' => ['op' => 'calc', 'operator' => 'add_days', 'operand' => '1.5']], 'bulk.days'],
            [['notiz' => ['op' => 'replace', 'search' => '']], 'bulk.search_empty'],
            [['tarif' => ['op' => 'formula', 'formula' => 'tarif * bezeichnung']], 'bulk.formula'],
            [['tarif' => ['op' => 'formula', 'formula' => '']], 'bulk.formula'],
            [['notiz' => ['op' => 'formula', 'formula' => '1']], 'bulk.not_editable'],
            [['beginn' => ['op' => 'replace', 'search' => '1']], 'bulk.not_editable'],
        ] as [$changes, $code]) {
            try {
                BulkChange::fromArray($table, $changes)->validate();
                $this->fail("{$code} erwartet.");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode, json_encode($changes));
            }
        }
    }

    public function test_preview_shows_only_changing_examples_counts_and_errors(): void
    {
        $table = $this->courses();
        $ids = $this->seedCourses($table, [['notiz' => 'Hauptstr. 1'], ['notiz' => 'Weg'], ['notiz' => null], ['notiz' => 'Dorfstr. 2']]);

        $preview = BulkPreview::for(BulkChange::fromArray($table, ['notiz' => ['op' => 'replace', 'search' => 'str.', 'replace' => 'strasse']]), $ids);

        $this->assertSame([$ids[0], $ids[3]], array_map(static fn (array $e): int => (int) $e['record']->getKey(), $preview->examples));
        $this->assertSame(['notiz' => 'Hauptstrasse 1'], $preview->examples[0]['after']);
        $this->assertSame(['changing' => 2, 'unchanged' => 0, 'skipped' => 2, 'failed' => 0], $preview->counts);
        $this->assertFalse($preview->partial);

        $ids = $this->seedCourses($table, [['tarif' => '5', 'plaetze' => 0, 'kuerzel' => 'Z']]);
        $preview = BulkPreview::for(BulkChange::fromArray($table, ['tarif' => ['op' => 'formula', 'formula' => 'tarif / plaetze']]), $ids);
        $this->assertSame(1, $preview->counts['failed']);
        $this->assertSame(['tarif' => 'Division durch 0.'], $preview->examples[0]['errors']);
    }
}
