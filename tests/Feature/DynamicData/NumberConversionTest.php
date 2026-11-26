<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Numbering\NumberConversion;
use App\DynamicData\Numbering\NumberCounter;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Numbering\NumberSequences;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Work\Types\NumberBackfillWork;
use App\Work\WorkService;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** Nummernkreise (B-135, N2): bestehende Felder umstellen, leere Nummern vergeben, Zählerstand setzen. */
class NumberConversionTest extends DynamicDataTestCase
{
    private const array YEARLY = [NumberPattern::PATTERN => 'R-{YYYY}-{000}', NumberPattern::RESET => 'yearly', NumberPattern::DATE_FIELD => 'datum'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    /**
     * Rechnungen mit einem vorhandenen Nummernfeld vom Typ $type und Datensätzen [datum, nummer].
     *
     * @param  array<string, mixed>  $field
     * @param  list<array{0: string, 1: mixed}>  $rows
     */
    private function invoices(array $field, array $rows): CustomTable
    {
        $table = $this->service()->createTable(['name' => 'rechnungen', 'label' => 'Rechnungen'], [
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date', 'is_required' => true],
            ['name' => 'nummer', 'label' => 'Nummer', ...$field],
        ]);

        foreach ($rows as [$date, $number]) {
            $this->writer()->create($table, ['datum' => $date, 'nummer' => $number], WriteOptions::seed());
        }

        return $table->fresh();
    }

    /** @param  array<string, mixed>  $options */
    private function convert(CustomTable $table, array $options = self::YEARLY): CustomField
    {
        return $this->service()->updateField($this->field($table), ['type' => 'number', 'options' => $options]);
    }

    private function field(CustomTable $table, string $name = 'nummer'): CustomField
    {
        return CustomField::query()->findOrFail($this->fieldId($table->name, $name));
    }

    private function writer(): RecordWriter
    {
        return app(RecordWriter::class);
    }

    /** @return list<mixed> Nummern nach Schlüssel */
    private function numbers(CustomTable $table): array
    {
        return DynamicRecord::forTable($table->fresh())->newQuery()->withTrashed()->orderBy('id')->pluck('nummer')->all();
    }

    private function assertRejected(string $code, Closure $write, ?string $message = null): void
    {
        try {
            $write();
            $this->fail("BusinessRuleException {$code} erwartet.");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_text_values_are_kept_and_the_counter_continues_after_the_highest(): void
    {
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [
            ['2026-09-01', 'R-2026-001'], ['2026-09-01', 'R-2026-007'], ['2025-12-01', 'R-2025-012'], ['2026-10-01', 'ALT-1'], ['2026-10-02', null],
        ]);

        $field = $this->convert($table);

        $this->assertSame(FieldType::Number, $field->type);
        $this->assertTrue($field->is_unique);
        $this->assertSame(['R-2026-001', 'R-2026-007', 'R-2025-012', 'ALT-1', null], $this->numbers($table));
        $this->assertSame(['2025' => 12, '2026' => 7], app(NumberSequences::class)->periods($field->id));
        $this->assertSame('R-2026-008', $this->writer()->create($table->fresh(), ['datum' => '2026-11-20'], WriteOptions::ui())->nummer);
    }

    public function test_integers_are_formatted_when_the_pattern_is_only_the_counter(): void
    {
        $table = $this->invoices(['type' => 'integer'], [['2026-01-01', 1], ['2026-01-02', 42], ['2026-01-03', 12345]]);

        $field = $this->convert($table, [NumberPattern::PATTERN => '{0000}', NumberPattern::RESET => 'never']);

        $this->assertSame(['0001', '0042', '12345'], $this->numbers($table));
        $this->assertSame([NumberPattern::NO_PERIOD => 12345], app(NumberSequences::class)->periods($field->id));
        $this->assertSame('12346', $this->writer()->create($table->fresh(), ['datum' => '2026-11-20'], WriteOptions::ui())->nummer);
    }

    public function test_integers_stay_as_text_with_another_pattern(): void
    {
        $table = $this->invoices(['type' => 'integer'], [['2026-01-01', 5]]);

        $field = $this->convert($table, [NumberPattern::PATTERN => 'K-{000}', NumberPattern::RESET => 'never']);

        $this->assertSame(['5'], $this->numbers($table));
        $this->assertSame([], app(NumberSequences::class)->periods($field->id));
        $this->assertSame('K-001', $this->writer()->create($table->fresh(), ['datum' => '2026-11-20'], WriteOptions::ui())->nummer);
    }

    public function test_choice_values_convert_when_they_fit(): void
    {
        $choices = [['value' => 'A-1', 'label' => 'eins'], ['value' => 'A-2', 'label' => 'zwei']];
        $table = $this->invoices(['type' => 'select', 'options' => ['options' => $choices]], [['2026-01-01', 'A-1'], ['2026-01-02', 'A-2']]);

        $field = $this->convert($table, [NumberPattern::PATTERN => 'A-{#}', NumberPattern::RESET => 'never']);

        $this->assertSame(['A-1', 'A-2'], $this->numbers($table));
        $this->assertSame('A-3', $this->writer()->create($table->fresh(), ['datum' => '2026-11-20'], WriteOptions::ui())->nummer);
        $this->assertSame(FieldType::Number, $field->type);
    }

    public function test_duplicates_block_the_conversion_but_not_in_the_recycle_bin(): void
    {
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2026-01-01', 'R-2026-001'], ['2026-01-02', 'R-2026-001']]);

        $this->assertRejected('dynamic.schema', fn () => $this->convert($table), 'R-2026-001');
        $this->assertSame(FieldType::String, $this->field($table)->type);
        $this->assertStringContainsString('R-2026-001', (string) $this->service()->fieldChangeProblem($this->field($table), ['type' => 'number', 'options' => self::YEARLY]));

        $this->writer()->delete(DynamicRecord::forTable($table)->newQuery()->orderByDesc('id')->firstOrFail());
        $this->convert($table);

        $this->assertSame(FieldType::Number, $this->field($table)->type);
    }

    public function test_values_longer_than_the_number_column_block_the_conversion(): void
    {
        $table = $this->invoices(['type' => 'text'], [['2026-01-01', str_repeat('x', 70)]]);

        $this->assertRejected('dynamic.schema', fn () => $this->convert($table, [NumberPattern::PATTERN => '{0000}', NumberPattern::RESET => 'never']));
    }

    public function test_number_goes_back_to_text_with_values_and_forgets_its_counters(): void
    {
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2026-01-01', 'R-2026-003']]);
        $field = $this->convert($table);

        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($field, ['type' => 'integer', 'options' => []]));

        $field = $this->service()->updateField($field->fresh(), ['type' => 'string', 'options' => ['length' => 60]]);

        $this->assertSame(FieldType::String, $field->type);
        $this->assertSame(['R-2026-003'], $this->numbers($table));
        $this->assertSame([], app(NumberSequences::class)->periods($field->id));
    }

    public function test_analysis_summarises_matching_and_mismatching_values(): void
    {
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [
            ['2026-01-01', 'R-2026-001'], ['2026-01-02', 'R-2026-0002'], ['2026-01-03', null], ['2026-01-04', 'R-2026-009'],
        ]);

        $analysis = app(NumberConversion::class)->analyze($table, 'nummer', NumberPattern::fromOptions(self::YEARLY), FieldType::String);

        $this->assertSame([3, 2, 1, ['2026' => 9], ['R-2026-0002'], []], [$analysis->values, $analysis->matching, $analysis->empty, $analysis->highest, $analysis->mismatches, $analysis->duplicates]);
        $this->assertStringContainsString('R-2026-0002', $analysis->summary());
    }

    public function test_backfill_numbers_empty_values_in_date_order_and_logs_the_run(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [
            ['2026-03-01', null], ['2026-02-01', null], ['2026-01-01', 'R-2026-004'], ['2025-12-01', null], ['2026-04-01', null],
        ]);
        $field = $this->convert($table);
        $this->writer()->delete(DynamicRecord::forTable($table->fresh())->newQuery()->orderByDesc('id')->firstOrFail());

        $this->assertSame(3, NumberBackfillWork::pending($field));
        $work = app(WorkService::class)->startAndDrive(NumberBackfillWork::KEY, ['field' => $field->id], $admin);

        $this->assertSame(WorkItem::DONE, $work->status, (string) $work->message);
        $this->assertSame(['R-2026-006', 'R-2026-005', 'R-2026-004', 'R-2025-001', null], $this->numbers($table), 'Nach Bezugsdatum; Papierkorb bleibt leer.');

        $run = $work->result['run'];
        $logged = Activity::query()->where('log_name', 'dynamic.rechnungen')->get()->filter(fn (Activity $a): bool => ($a->properties['run'] ?? null) === $run);
        $this->assertCount(3, $logged);
        $this->assertSame(WriteOptions::NUMBERING, $logged->first()->properties['via']);
        $this->assertSame(0, NumberBackfillWork::pending($field));
    }

    public function test_backfill_without_date_field_uses_the_creation_date(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        Carbon::setTestNow('2025-06-01 10:00:00');
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2025-06-01', null]]);
        Carbon::setTestNow('2026-11-26 10:00:00');
        $field = $this->convert($table, [NumberPattern::PATTERN => 'K{YY}-{00}', NumberPattern::RESET => 'yearly']);

        app(WorkService::class)->startAndDrive(NumberBackfillWork::KEY, ['field' => $field->id], $admin);

        $this->assertSame(['K25-01'], $this->numbers($table));
    }

    public function test_numbering_origin_cannot_change_an_assigned_number(): void
    {
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2026-01-01', 'R-2026-001']]);
        $this->convert($table);
        $record = DynamicRecord::forTable($table->fresh())->newQuery()->firstOrFail();

        $this->assertRejected('dynamic.number_immutable', fn () => $this->writer()->update($record, ['nummer' => 'R-2026-099'], new WriteOptions(origin: WriteOptions::NUMBERING)));
    }

    public function test_counter_can_be_raised_and_lowered_only_without_conflict(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2026-01-01', 'R-2026-005']]);
        $field = $this->convert($table);
        $counter = app(NumberCounter::class);

        $counter->set($field, '2027', 999, false);
        $this->assertSame('R-2027-1000', $this->writer()->create($table->fresh(), ['datum' => '2027-01-05'], WriteOptions::ui())->nummer);

        $counter->set($field, '2026', 50, false);
        $this->assertRejected('dynamic.number_counter_confirm', fn () => $counter->set($field, '2026', 10, false));
        $this->assertRejected('dynamic.number_counter_conflict', fn () => $counter->set($field, '2026', 4, true));
        $this->assertRejected('dynamic.number_counter', fn () => $counter->set($field, '26', 4, true));

        $counter->set($field, '2026', 5, true);
        $this->assertSame(['2026' => ['counter' => 5, 'highest' => 5], '2027' => ['counter' => 1000, 'highest' => 1000]], $counter->overview($field));

        $log = Activity::query()->where('subject_type', $field->getMorphClass())->where('event', 'number_counter')->latest('id')->firstOrFail();
        $this->assertSame(50, $log->attribute_changes['old'][__('numbering.counter.log_attribute', ['period' => '2026'])]);
    }

    private function fieldList(CustomTable $table): Testable
    {
        return Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class]);
    }

    public function test_field_editor_previews_the_conversion_and_numbers_empty_values(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2026-01-01', 'R-2026-003'], ['2026-02-01', 'ALT-7'], ['2026-03-01', null]]);
        $field = $this->field($table);

        $this->fieldList($table)
            ->mountAction(TestAction::make(EditAction::class)->table($field))
            ->fillForm(['type' => 'number', 'options' => self::YEARLY])
            ->assertMountedActionModalSee(['2 Werte, davon 1 passend zum Muster', '1 Datensatz ohne Wert', 'ALT-7', __('numbering.conversion.backfill')])
            ->fillForm(['number_backfill' => true])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(FieldType::Number, $field->fresh()->type);
        $this->assertSame(['R-2026-003', 'ALT-7', 'R-2026-004'], $this->numbers($table));
    }

    public function test_field_editor_blocks_duplicates(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2026-01-01', 'R-2026-003'], ['2026-02-01', 'R-2026-003']]);

        $this->fieldList($table)
            ->callAction(TestAction::make(EditAction::class)->table($this->field($table)), ['type' => 'number', 'options' => self::YEARLY])
            ->assertHasFormErrors(['options.'.NumberPattern::PATTERN]);

        $this->assertSame(FieldType::String, $this->field($table)->type);
    }

    public function test_field_list_numbers_empty_values_on_request_and_shows_counters(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->invoices(['type' => 'string', 'options' => ['length' => 50]], [['2026-01-01', 'R-2026-003'], ['2026-03-01', null]]);
        $field = $this->convert($table);

        $this->fieldList($table)
            ->assertActionVisible(TestAction::make('numberNow')->table($field))
            ->callAction(TestAction::make('numberNow')->table($field))
            ->assertActionHidden(TestAction::make('numberNow')->table($field))
            ->mountAction(TestAction::make(EditAction::class)->table($field))
            ->assertMountedActionModalSee(['2026: zuletzt vergeben 4, höchste vorhandene Nummer 4', __('numbering.counter.set')]);

        $this->assertSame(['R-2026-003', 'R-2026-004'], $this->numbers($table));
    }
}
