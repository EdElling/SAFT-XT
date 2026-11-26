<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\RecordCopier;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Plugins\Api\Events\RecordSaving;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/** Nummernkreise (B-135, N3): „Vergeben, wenn“ – Vergabe beim ersten Speichern, bei dem die Bedingung zutrifft. */
class NumberConditionTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    /** @param  array<string, mixed>  $options */
    private function invoices(array $options = [], string $condition = "status <> 'entwurf'"): CustomTable
    {
        return $this->service()->createTable(['name' => 'rechnungen', 'label' => 'Rechnungen'], [
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date', 'is_required' => true],
            ['name' => 'betreff', 'label' => 'Betreff', 'type' => 'string'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'is_required' => true, 'options' => ['options' => [
                ['value' => 'entwurf', 'label' => ['de' => 'Entwurf']],
                ['value' => 'versendet', 'label' => ['de' => 'Versendet']],
                ['value' => 'storniert', 'label' => ['de' => 'Storniert']],
            ]]],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal'],
            ['name' => 'brutto', 'label' => 'Brutto', 'type' => 'computed', 'options' => ['formula' => 'betrag * 1.081']],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [
                NumberPattern::PATTERN => 'R-{YYYY}-{0000}', NumberPattern::RESET => 'yearly', NumberPattern::DATE_FIELD => 'datum',
                NumberCondition::OPTION => $condition, ...$options,
            ]],
        ]);
    }

    private function writer(): RecordWriter
    {
        return app(RecordWriter::class);
    }

    /** @param  array<string, mixed>  $values */
    private function create(CustomTable $table, array $values = [], ?WriteOptions $options = null): DynamicRecord
    {
        return $this->writer()->create($table, ['datum' => '2026-11-20', 'status' => 'entwurf', ...$values], $options ?? WriteOptions::ui());
    }

    /** @param  array<string, mixed>  $values */
    private function update(DynamicRecord $record, array $values): DynamicRecord
    {
        return $this->writer()->update($record->fresh(), $values, WriteOptions::ui());
    }

    private function assertRejected(string $code, Closure $write): void
    {
        try {
            $write();
            $this->fail("BusinessRuleException {$code} erwartet.");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $options */
    private function addNumber(CustomTable $table, array $options): CustomField
    {
        return $this->service()->addField($table->fresh(), ['name' => 'nr2', 'label' => 'Nr', 'type' => 'number', 'options' => [NumberPattern::PATTERN => '{#}', ...$options]]);
    }

    public function test_condition_is_stored_as_formula_and_tree(): void
    {
        $table = $this->invoices([], "  status<>'entwurf' ");
        $options = $table->fields->firstWhere('name', 'nummer')->optionsArray();

        $this->assertSame("status <> 'entwurf'", $options[NumberCondition::OPTION]);
        $this->assertSame(['status'], NumberCondition::fields($options));

        $plain = $this->addNumber($table, [NumberCondition::OPTION => ' '])->optionsArray();
        $this->assertArrayNotHasKey(NumberCondition::OPTION, $plain, 'Leer: beim Anlegen, keine Option.');
    }

    public function test_invalid_conditions_are_rejected(): void
    {
        $table = $this->invoices();

        $this->assertRejected('dynamic.invalid_field', fn () => $this->addNumber($table, [NumberCondition::OPTION => "zustand = 'x'"]));
        $this->assertRejected('dynamic.invalid_field', fn () => $this->addNumber($table, [NumberCondition::OPTION => "status = 'x' and"]));
        $this->assertRejected('dynamic.invalid_field', fn () => $this->addNumber($table, [NumberCondition::OPTION => 'brutto > 100']));
        $this->assertRejected('dynamic.invalid_field', fn () => $this->addNumber($table, [NumberCondition::OPTION => "nr2 = 'x'"]));

        $this->assertSame('betrag > 100', $this->addNumber($table, [NumberCondition::OPTION => 'betrag > 100'])->optionsArray()[NumberCondition::OPTION]);
    }

    public function test_number_is_assigned_on_the_first_save_where_the_condition_holds(): void
    {
        $table = $this->invoices();
        $draft = $this->create($table, ['betreff' => 'Entwurf']);
        $this->assertNull($draft->nummer, 'Entwurf: keine Nummer.');

        $this->assertSame('R-2026-0001', $this->create($table, ['status' => 'versendet'])->nummer, 'Beim Anlegen, wenn die Bedingung schon zutrifft.');

        $this->assertNull($this->update($draft, ['betreff' => 'noch Entwurf'])->nummer);
        $sent = $this->update($draft, ['status' => 'versendet']);
        $this->assertSame('R-2026-0002', $sent->nummer, 'Beim Ändern, sobald die Bedingung zutrifft.');

        $this->assertSame('R-2026-0002', $this->update($sent, ['status' => 'entwurf'])->nummer, 'Die Nummer bleibt, auch wenn die Bedingung wieder falsch wird.');
        $this->assertSame('R-2026-0002', $this->update($sent, ['status' => 'storniert'])->nummer, 'Kein zweites Mal vergeben.');
        $this->assertRejected('dynamic.number_immutable', fn () => $this->update($sent, ['nummer' => 'R-2026-0009']));
    }

    public function test_drafts_leave_no_gaps(): void
    {
        $table = $this->invoices();
        $draft = $this->create($table);
        $this->writer()->forceDelete($draft->fresh(), WriteOptions::ui());

        $this->assertSame('R-2026-0001', $this->create($table, ['status' => 'versendet'])->nummer);
    }

    public function test_reference_date_is_taken_at_assignment(): void
    {
        $table = $this->invoices();
        $draft = $this->create($table, ['datum' => '2026-12-20']);

        $this->assertSame('R-2027-0001', $this->update($draft, ['datum' => '2027-01-05', 'status' => 'versendet'])->nummer, 'Datumsfeld zum Zeitpunkt der Vergabe.');

        $plain = $this->service()->createTable(['name' => 'mahnungen', 'label' => 'Mahnungen'], [
            ['name' => 'definitiv', 'label' => 'Definitiv', 'type' => 'boolean'],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [
                NumberPattern::PATTERN => 'M-{YYYY}-{00}', NumberPattern::RESET => 'yearly', NumberCondition::OPTION => 'definitiv',
            ]],
        ]);
        $reminder = $this->writer()->create($plain, ['definitiv' => false], WriteOptions::ui());

        Carbon::setTestNow('2027-01-04 09:00:00');
        $this->assertSame('M-2027-01', $this->writer()->update($reminder->fresh(), ['definitiv' => true], WriteOptions::ui())->nummer, 'Ohne Datumsfeld: Zeitpunkt der Vergabe, nicht der Anlage.');
    }

    public function test_condition_sees_values_set_by_plugins(): void
    {
        $table = $this->invoices();
        Event::listen(RecordSaving::class, static function (RecordSaving $event): void {
            if ($event->record->getAttribute('betreff') === 'automatisch') {
                $event->record->setAttribute('status', 'versendet');
            }
        });

        $this->assertSame('R-2026-0001', $this->create($table, ['betreff' => 'automatisch'])->nummer);
        $this->assertNull($this->create($table, ['betreff' => 'von Hand'])->nummer);
    }

    public function test_condition_on_a_decimal_field(): void
    {
        $table = $this->invoices([], 'betrag >= 100');

        $this->assertNull($this->create($table, ['betrag' => '99.95'])->nummer);
        $this->assertNull($this->create($table)->nummer, 'Leerer Wert: Vergleich nicht erfüllt.');
        $this->assertSame('R-2026-0001', $this->create($table, ['betrag' => '100'])->nummer);
    }

    public function test_inactive_field_assigns_nothing(): void
    {
        $table = $this->invoices();
        $this->service()->updateField($table->fields->firstWhere('name', 'nummer'), ['is_active' => false]);

        $this->assertNull($this->create($table->fresh(), ['status' => 'versendet'])->nummer);
    }

    public function test_import_keeps_given_numbers_and_assigns_only_where_the_condition_holds(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->service()->updateTable($this->invoices(), ['import_enabled' => true]);
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, "Datum;Betreff;Status;Nummer\n2026-11-20;Alt;versendet;R-2026-0010\n2026-11-20;Alter Entwurf;entwurf;R-2026-0011\n2026-11-21;Neu;versendet;\n2026-11-21;Neuer Entwurf;entwurf;\n");

        Storage::fake('local');
        $this->assertSame(WorkItem::DONE, $this->importRecords($table, $path, 'rechnungen.csv')->status);
        unlink($path);

        $this->assertSame(
            ['Alt' => 'R-2026-0010', 'Alter Entwurf' => 'R-2026-0011', 'Neu' => 'R-2026-0012', 'Neuer Entwurf' => null],
            DynamicRecord::forTable($table)->newQuery()->orderBy('id')->pluck('nummer', 'betreff')->all(),
            'Mitgebrachte Nummern bleiben ohne Prüfung der Bedingung; leere werden nur bei erfüllter Bedingung vergeben.',
        );
    }

    public function test_copy_gets_a_number_only_when_the_condition_holds(): void
    {
        $table = $this->invoices();
        $draft = $this->create($table, ['betreff' => 'Entwurf']);
        $sent = $this->create($table, ['betreff' => 'Versendet', 'status' => 'versendet']);

        $copy = static fn (DynamicRecord $original): DynamicRecord => app(RecordCopier::class)->copy($table, $original, RecordCopier::values($table, $original))['record'];

        $this->assertNull($copy($draft)->nummer);
        $this->assertSame('R-2026-0002', $copy($sent)->nummer, 'Die Bedingung gilt für die Werte der Kopie.');
    }

    public function test_fields_used_by_the_condition_follow_renames_and_stay_active(): void
    {
        $table = $this->invoices();
        $status = $table->fields->firstWhere('name', 'status');

        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($status, ['is_active' => false]));

        $this->service()->updateField($status->fresh(), ['name' => 'zustand']);
        $options = CustomField::query()->findOrFail($this->fieldId('rechnungen', 'nummer'))->optionsArray();
        $this->assertSame("zustand <> 'entwurf'", $options[NumberCondition::OPTION], 'Umbenennen führt die Bedingung nach.');

        $record = $this->writer()->create($table->fresh(), ['datum' => '2026-11-20', 'zustand' => 'versendet'], WriteOptions::ui());
        $this->assertSame('R-2026-0001', $record->nummer);
    }
}
