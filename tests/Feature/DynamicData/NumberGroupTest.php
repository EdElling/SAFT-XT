<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberCounter;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Numbering\NumberSequences;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Work\Types\NumberBackfillWork;
use App\Work\WorkService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** Nummernkreise (B-135, N4): eigener Zähler je Gruppe, Platzhalter {GROUP} mit Kurzzeichen je Wahl. */
class NumberGroupTest extends DynamicDataTestCase
{
    private const array GROUPED = [
        NumberPattern::PATTERN => '{GROUP}-{YYYY}-{000}', NumberPattern::RESET => 'yearly', NumberPattern::DATE_FIELD => 'datum',
        NumberPattern::GROUP_FIELD => 'typ', NumberPattern::GROUP_CODES => ['Einnahme' => 'V', 'Ausgabe' => 'E'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    /**
     * Belege mit Typ (Einnahme/Ausgabe) und Status; Nummer nach $options (null: noch keine Nummer).
     *
     * @param  array<string, mixed>|null  $options
     * @param  array<string, mixed>  $number
     */
    private function receipts(?array $options = self::GROUPED, array $number = []): CustomTable
    {
        return $this->service()->createTable(['name' => 'belege', 'label' => 'Belege', 'import_enabled' => true], [
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date', 'is_required' => true],
            ['name' => 'typ', 'label' => 'Typ', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                ['value' => 'Einnahme', 'label' => ['de' => 'Einnahme']],
                ['value' => 'Ausgabe', 'label' => ['de' => 'Ausgabe']],
            ]]],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                ['value' => 'entwurf', 'label' => ['de' => 'Entwurf']],
                ['value' => 'definitiv', 'label' => ['de' => 'Definitiv']],
            ]]],
            ['name' => 'betreff', 'label' => 'Betreff', 'type' => 'string'],
            ...($options === null ? [] : [['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => $options, ...$number]]),
        ]);
    }

    /** @param  array<string, mixed>  $values */
    private function create(CustomTable $table, array $values = [], ?WriteOptions $options = null): DynamicRecord
    {
        return app(RecordWriter::class)->create($table->fresh(), ['datum' => '2026-11-20', 'typ' => 'Einnahme', 'status' => 'definitiv', ...$values], $options ?? WriteOptions::ui());
    }

    private function field(string $name = 'nummer'): CustomField
    {
        return CustomField::query()->findOrFail($this->fieldId('belege', $name));
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

    public function test_each_group_counts_on_its_own(): void
    {
        $table = $this->receipts();

        $numbers = array_map(fn (array $v): string => $this->create($table, $v)->nummer, [
            ['typ' => 'Einnahme'], ['typ' => 'Ausgabe'], ['typ' => 'Einnahme'], ['typ' => 'Ausgabe', 'datum' => '2025-12-31'], ['typ' => 'Ausgabe'],
        ]);

        $this->assertSame(['V-2026-001', 'E-2026-001', 'V-2026-002', 'E-2025-001', 'E-2026-002'], $numbers);
        $this->assertSame([
            ['group' => 'E', 'period' => '2025', 'value' => 1],
            ['group' => 'E', 'period' => '2026', 'value' => 2],
            ['group' => 'V', 'period' => '2026', 'value' => 2],
        ], app(NumberSequences::class)->counters($this->field()->id));
    }

    public function test_codes_default_to_the_choice_value(): void
    {
        $table = $this->receipts([...self::GROUPED, NumberPattern::PATTERN => '{YYYY}/{GROUP}/{#}', NumberPattern::GROUP_CODES => ['Ausgabe' => 'AUS', 'Alt' => 'ALT', 'Leer' => ' ']]);

        $this->assertEquals(['Ausgabe' => 'AUS', 'Alt' => 'ALT', 'Einnahme' => 'Einnahme'], $this->field()->optionsArray()[NumberPattern::GROUP_CODES], 'Fehlende Kurzzeichen sind der Wert; Einträge für andere Werte bleiben.');
        $this->assertSame('2026/Einnahme/1', $this->create($table)->nummer);
        $this->assertSame('2026/AUS/1', $this->create($table, ['typ' => 'Ausgabe'])->nummer);
    }

    public function test_invalid_group_settings_are_rejected(): void
    {
        $table = $this->receipts(null);
        $add = fn (array $options): CustomField => $this->service()->addField($table->fresh(), ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [...self::GROUPED, ...$options]]);

        $this->assertRejected('dynamic.invalid_field', fn () => $add([NumberPattern::PATTERN => '{YYYY}-{000}']), '{GROUP}');
        $this->assertRejected('dynamic.invalid_field', fn () => $add([NumberPattern::GROUP_FIELD => '']), '{GROUP}');
        $this->assertRejected('dynamic.invalid_field', fn () => $add([NumberPattern::GROUP_FIELD => 'betreff']), 'Auswahl');
        $this->assertRejected('dynamic.invalid_field', fn () => $add([NumberPattern::GROUP_CODES => ['Einnahme' => 'Ü']]), 'Ü');

        $this->service()->addField($table->fresh(), ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [['value' => 'A'], ['value' => 'Rück']]]]);
        $this->assertRejected('dynamic.invalid_field', fn () => $add([NumberPattern::GROUP_FIELD => 'art', NumberPattern::GROUP_CODES => []]), 'Rück');
        $this->assertSame('B', $add([NumberPattern::GROUP_FIELD => 'art', NumberPattern::GROUP_CODES => ['Rück' => 'B']])->optionsArray()[NumberPattern::GROUP_CODES]['Rück']);

        $this->service()->addField($table->fresh(), ['name' => 'art2', 'label' => 'Art 2', 'type' => 'select', 'options' => ['options' => [['value' => 'A']]]]);
        $this->assertRejected('dynamic.invalid_field', fn () => $add([NumberPattern::GROUP_FIELD => 'art2', NumberPattern::GROUP_CODES => []]), 'Pflichtfeld');
    }

    public function test_number_stays_when_the_group_changes(): void
    {
        $table = $this->receipts();
        $record = $this->create($table);

        $updated = app(RecordWriter::class)->update($record->fresh(), ['typ' => 'Ausgabe'], WriteOptions::ui());

        $this->assertSame('V-2026-001', $updated->fresh()->nummer);
        $this->assertSame('E-2026-001', $this->create($table, ['typ' => 'Ausgabe'])->nummer, 'Der Zähler der neuen Gruppe bleibt unberührt.');
    }

    public function test_condition_uses_the_group_at_assignment(): void
    {
        $table = $this->receipts([...self::GROUPED, NumberCondition::OPTION => "status = 'definitiv'"]);
        $draft = $this->create($table, ['status' => 'entwurf', 'typ' => 'Einnahme']);
        $this->assertNull($draft->nummer);

        $final = app(RecordWriter::class)->update($draft->fresh(), ['status' => 'definitiv', 'typ' => 'Ausgabe'], WriteOptions::ui());

        $this->assertSame('E-2026-001', $final->nummer);
    }

    public function test_missing_group_is_rejected(): void
    {
        $table = $this->receipts();

        $this->assertRejected('dynamic.number_group_missing', fn () => $this->create($table, ['typ' => null], WriteOptions::seed()));
    }

    public function test_import_raises_the_counter_of_the_group_in_the_number(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->receipts();
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, "Datum;Typ;Status;Nummer\n2026-11-20;Einnahme;definitiv;V-2026-010\n2026-11-20;Ausgabe;definitiv;E-2026-004\n2026-11-21;Ausgabe;definitiv;\n2026-11-21;Einnahme;definitiv;\n");

        Storage::fake('local');
        $this->assertSame(WorkItem::DONE, $this->importRecords($table, $path, 'belege.csv')->status);
        unlink($path);

        $this->assertSame(['V-2026-010', 'E-2026-004', 'E-2026-005', 'V-2026-011'], DynamicRecord::forTable($table)->newQuery()->orderBy('id')->pluck('nummer')->all());
    }

    public function test_group_field_stays_a_required_select_and_follows_renames(): void
    {
        $this->receipts();
        $typ = $this->field('typ');

        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($typ, ['is_active' => false]), 'Gruppe');
        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($typ, ['is_required' => false]), 'Gruppe');
        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($typ, ['type' => 'string']), 'Gruppe');
        $this->assertRejected('dynamic.schema', fn () => $this->service()->dropField($typ, 'typ'));

        $this->service()->updateField($typ->fresh(), ['name' => 'belegart']);

        $this->assertSame('belegart', $this->field()->optionsArray()[NumberPattern::GROUP_FIELD]);
        $record = app(RecordWriter::class)->create(CustomTable::query()->where('name', 'belege')->sole(), ['datum' => '2026-11-20', 'belegart' => 'Ausgabe', 'status' => 'definitiv'], WriteOptions::ui());
        $this->assertSame('E-2026-001', $record->nummer);
    }

    public function test_new_choices_need_a_valid_code(): void
    {
        $table = $this->receipts();
        $choices = fn (array ...$more): array => ['options' => ['options' => [['value' => 'Einnahme'], ['value' => 'Ausgabe'], ...$more]]];

        $this->service()->updateField($this->field('typ'), $choices(['value' => 'Gutschrift']));
        $this->assertEquals(['Einnahme' => 'V', 'Ausgabe' => 'E', 'Gutschrift' => 'Gutschrift'], $this->field()->optionsArray()[NumberPattern::GROUP_CODES], 'Ein gültiger Wert wird zum Kurzzeichen.');

        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($this->field('typ'), $choices(['value' => 'Rückerstattung'])), 'Rückerstattung');

        $this->service()->updateField($this->field(), ['options' => [...self::GROUPED, NumberPattern::GROUP_CODES => ['Einnahme' => 'V', 'Ausgabe' => 'E', 'Rückerstattung' => 'RE']]]);
        $this->service()->updateField($this->field('typ'), $choices(['value' => 'Rückerstattung']));
        $this->assertSame('RE-2026-001', $this->create($table, ['typ' => 'Rückerstattung'])->nummer, 'Mit hinterlegtem Kurzzeichen geht es.');

        // Entfernte Wahlen behalten ihr Kurzzeichen (alte Nummern zurücklesen).
        $this->service()->updateField($this->field('typ'), $choices());
        $this->assertEquals(['Einnahme' => 'V', 'Ausgabe' => 'E', 'Rückerstattung' => 'RE'], array_diff_key($this->field()->optionsArray()[NumberPattern::GROUP_CODES], ['Gutschrift' => 0]));
    }

    public function test_changed_code_starts_a_new_counter_and_raises_from_existing_numbers(): void
    {
        $table = $this->receipts();
        $this->create($table);
        $this->create($table);

        $this->service()->updateField($this->field(), ['options' => [...self::GROUPED, NumberPattern::GROUP_CODES => ['Einnahme' => 'EIN', 'Ausgabe' => 'E']]]);
        $this->assertSame('EIN-2026-001', $this->create($table)->nummer);

        // Zurück zu V: Der Zähler von V steht schon auf 2; vorhandene V-Nummern heben ihn, falls er fehlte.
        app(NumberSequences::class)->forget($this->field()->id);
        $this->service()->updateField($this->field(), ['options' => self::GROUPED]);
        $this->assertSame('V-2026-003', $this->create($table)->nummer);
    }

    public function test_text_field_converts_to_a_grouped_number(): void
    {
        $table = $this->receipts(null);
        $this->service()->addField($table->fresh(), ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'options' => ['length' => 30]]);

        foreach ([['Einnahme', 'V-2026-005'], ['Ausgabe', 'E-2026-002'], ['Ausgabe', 'E-2025-009'], ['Ausgabe', 'ALT-1']] as [$typ, $nummer]) {
            $this->create($table, ['typ' => $typ, 'nummer' => $nummer], WriteOptions::seed());
        }

        $field = $this->service()->updateField($this->field(), ['type' => 'number', 'options' => self::GROUPED]);

        $this->assertSame(['E 2025' => ['counter' => 9, 'highest' => 9], 'E 2026' => ['counter' => 2, 'highest' => 2], 'V 2026' => ['counter' => 5, 'highest' => 5]], app(NumberCounter::class)->overview($field));
        $this->assertSame('E-2026-003', $this->create($table, ['typ' => 'Ausgabe'])->nummer);
    }

    public function test_counter_is_set_per_group(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->receipts();
        $this->create($table);
        $counter = app(NumberCounter::class);

        $counter->set($this->field(), '2026', 99, false, group: 'E');
        $this->assertSame('E-2026-100', $this->create($table, ['typ' => 'Ausgabe'])->nummer);
        $this->assertSame('V-2026-002', $this->create($table)->nummer);

        $this->assertRejected('dynamic.number_counter_conflict', fn () => $counter->set($this->field(), '2026', 1, true, group: 'V'));
        $this->assertRejected('dynamic.number_counter', fn () => $counter->set($this->field(), '2026', 5, true, group: 'X'));
        $this->assertRejected('dynamic.number_counter', fn () => $counter->set($this->field(), '2026', 5, true), 'Gruppe');
    }

    public function test_backfill_numbers_per_group(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $table = $this->receipts();
        $field = $this->service()->updateField($this->field(), ['is_active' => false]);

        foreach ([['2026-03-01', 'Ausgabe'], ['2026-01-01', 'Einnahme'], ['2026-02-01', 'Ausgabe']] as [$datum, $typ]) {
            $this->create($table, ['datum' => $datum, 'typ' => $typ, 'betreff' => $datum], WriteOptions::seed());
        }

        $field = $this->service()->updateField($field->fresh(), ['is_active' => true]);
        $work = app(WorkService::class)->startAndDrive(NumberBackfillWork::KEY, ['field' => $field->id], $admin);

        $this->assertSame(WorkItem::DONE, $work->status, (string) $work->message);
        $this->assertSame(
            ['2026-01-01' => 'V-2026-001', '2026-02-01' => 'E-2026-001', '2026-03-01' => 'E-2026-002'],
            DynamicRecord::forTable($table)->newQuery()->orderBy('datum')->pluck('nummer', 'betreff')->all(),
        );
    }
}
