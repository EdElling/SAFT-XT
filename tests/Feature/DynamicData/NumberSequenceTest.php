<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Assistant\TableProposalSchema;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Numbering\NumberPattern;
use App\DynamicData\Numbering\NumberSequences;
use App\DynamicData\RecordCopier;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Nummernkreise (B-135, N1): Vergabe im Schreibweg, Neubeginn, keine Wiedervergabe, Schreibschutz, Table-Designer. */
class NumberSequenceTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    /** @param  array<string, mixed>  $options */
    private function invoices(array $options = []): CustomTable
    {
        return $this->service()->createTable(['name' => 'rechnungen', 'label' => 'Rechnungen'], [
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date', 'is_required' => true],
            ['name' => 'betreff', 'label' => 'Betreff', 'type' => 'string'],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [
                NumberPattern::PATTERN => 'R-{YYYY}-{0000}', NumberPattern::RESET => 'yearly', NumberPattern::DATE_FIELD => 'datum', ...$options,
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
        return $this->writer()->create($table, ['datum' => '2026-11-20', ...$values], $options ?? WriteOptions::ui());
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

    public function test_field_is_unique_text_with_normalized_options(): void
    {
        $field = $this->invoices()->fields->firstWhere('name', 'nummer');

        $this->assertTrue($field->is_unique);
        $this->assertFalse($field->is_required);
        $this->assertEquals([NumberPattern::PATTERN => 'R-{YYYY}-{0000}', NumberPattern::RESET => 'yearly', NumberPattern::DATE_FIELD => 'datum', NumberPattern::START => 1, 'length' => NumberPattern::COLUMN_LENGTH], $field->optionsArray());
        $this->assertTrue($this->appSchema()->hasTable(app(DynamicDataNamespace::class)->qualify(NumberSequences::TABLE)));
    }

    public function test_numbers_are_assigned_in_order(): void
    {
        $table = $this->invoices();

        $this->assertSame('R-2026-0001', $this->create($table)->nummer);
        $this->assertSame('R-2026-0002', $this->create($table)->nummer);
        $this->assertSame('R-2026-0003', DynamicRecord::forTable($table)->create(['datum' => '2026-01-01'])->nummer, 'Auch über DynamicRecord::create().');
    }

    public function test_restart_follows_the_reference_date(): void
    {
        $table = $this->invoices();

        $this->assertSame('R-2026-0001', $this->create($table, ['datum' => '2026-12-31'])->nummer);
        $this->assertSame('R-2027-0001', $this->create($table, ['datum' => '2027-01-02'])->nummer);
        $this->assertSame('R-2026-0002', $this->create($table, ['datum' => '2026-02-01'])->nummer, 'Nachträglich für das Vorjahr: dessen Zähler.');
    }

    public function test_fiscal_year_and_start_value(): void
    {
        $table = $this->invoices([NumberPattern::FISCAL_START => 7, NumberPattern::START => 500]);

        $this->assertSame('R-2025-0500', $this->create($table, ['datum' => '2026-06-30'])->nummer);
        $this->assertSame('R-2026-0500', $this->create($table, ['datum' => '2026-07-01'])->nummer);
        $this->assertSame('R-2026-0501', $this->create($table, ['datum' => '2027-06-30'])->nummer);
    }

    public function test_creation_date_without_date_field_and_never_reset(): void
    {
        $table = $this->service()->createTable(['name' => 'vertraege', 'label' => 'Verträge'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [NumberPattern::PATTERN => 'V{YY}-{#}', NumberPattern::RESET => 'never']],
        ]);

        $this->assertSame('V26-1', $this->writer()->create($table, [])->nummer);
        Carbon::setTestNow('2027-01-05');
        $this->assertSame('V27-2', $this->writer()->create($table, [])->nummer, 'Ohne Neubeginn zählt es weiter.');
    }

    public function test_deleted_numbers_are_never_reused(): void
    {
        $table = $this->invoices();
        $this->create($table);
        $second = $this->create($table);

        $this->writer()->delete($second);
        $this->assertSame('R-2026-0003', $this->create($table)->nummer);

        $this->writer()->forceDelete(DynamicRecord::forTable($table)->newQuery()->where('nummer', 'R-2026-0003')->firstOrFail());
        $this->assertSame('R-2026-0004', $this->create($table)->nummer);
    }

    public function test_failed_save_leaves_no_gap(): void
    {
        $table = $this->invoices();
        $this->create($table);

        Event::listen(RecordSaved::class, static function (RecordSaved $event): void {
            if ($event->value('betreff') === 'kaputt') {
                throw new RecordRejected('Nicht so.');
            }
        });

        try {
            $this->create($table, ['betreff' => 'kaputt']);
            $this->fail('RecordRejected erwartet.');
        } catch (RecordRejected) {
        }

        $this->assertSame('R-2026-0002', $this->create($table)->nummer, 'Der Zähler ist mit zurückgerollt.');
    }

    public function test_existing_numbers_are_skipped(): void
    {
        $table = $this->invoices();
        // Ohne Schreibweg eingefügt, z. B. aus einer Zeit vor dem Nummernkreis.
        DB::connection('app_data')->table(app(DynamicDataNamespace::class)->qualify('rechnungen'))
            ->insert(['datum' => '2026-01-01', 'nummer' => 'R-2026-0001', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('R-2026-0002', $this->create($table)->nummer);
    }

    public function test_assigned_numbers_cannot_be_changed(): void
    {
        $table = $this->invoices();
        $record = $this->create($table);

        $this->assertRejected('dynamic.number_immutable', fn () => $this->writer()->update($record, ['nummer' => 'R-2026-0999'], WriteOptions::plugin()));
        $this->assertRejected('dynamic.number_immutable', fn () => $this->writer()->update($record->fresh(), ['nummer' => 'X'], WriteOptions::import()));

        $this->writer()->update($record->fresh(), ['betreff' => 'Neu'], WriteOptions::ui());
        $this->assertSame('R-2026-0001', $record->fresh()->nummer, 'Andere Felder bleiben änderbar.');
    }

    public function test_listeners_cannot_set_or_change_numbers(): void
    {
        $table = $this->invoices();
        Event::listen(RecordSaving::class, static function (RecordSaving $event): void {
            $event->record->setAttribute('nummer', 'P-1');
        });

        $this->assertRejected('dynamic.number_immutable', fn () => $this->create($table));
    }

    public function test_given_numbers_only_from_import_and_seed(): void
    {
        $table = $this->invoices();

        $this->assertRejected('dynamic.number_given', fn () => $this->create($table, ['nummer' => 'R-2026-0005']));
        $this->assertRejected('dynamic.number_given', fn () => $this->create($table, ['nummer' => 'R-2026-0005'], WriteOptions::plugin()));

        $this->assertSame('R-2026-0005', $this->create($table, ['nummer' => 'R-2026-0005'], WriteOptions::import())->nummer);
        $this->assertSame('ALT-17', $this->create($table, ['nummer' => 'ALT-17'], WriteOptions::seed())->nummer, 'Fremde Werte bleiben, wie sie sind.');
        $this->assertSame('R-2026-0006', $this->create($table)->nummer, 'Der Zähler ist auf die übernommene Nummer angehoben.');
        $this->assertSame('R-2025-0001', $this->create($table, ['datum' => '2025-05-05'])->nummer, 'Andere Perioden bleiben unberührt.');
    }

    public function test_missing_reference_date_is_rejected(): void
    {
        $table = $this->invoices();

        $this->assertRejected('dynamic.number_date_missing', fn () => $this->writer()->create($table, ['betreff' => 'ohne Datum'], WriteOptions::plugin()));
    }

    public function test_inactive_field_assigns_nothing(): void
    {
        $table = $this->invoices();
        $this->service()->updateField($table->fields->firstWhere('name', 'nummer'), ['is_active' => false]);

        $this->assertNull($this->create($table->fresh())->getAttribute('nummer'));
    }

    public function test_reference_date_must_be_a_required_date_field(): void
    {
        $table = $this->invoices();

        $this->assertRejected('dynamic.invalid_field', fn () => $this->service()->addField($table, ['name' => 'nr2', 'label' => 'Nr', 'type' => 'number', 'options' => [NumberPattern::PATTERN => '{#}', NumberPattern::DATE_FIELD => 'betreff']]));

        $this->service()->addField($table, ['name' => 'geliefert', 'label' => 'Geliefert', 'type' => 'date']);
        $this->assertRejected('dynamic.invalid_field', fn () => $this->service()->addField($table->fresh(), ['name' => 'nr2', 'label' => 'Nr', 'type' => 'number', 'options' => [NumberPattern::PATTERN => '{#}', NumberPattern::DATE_FIELD => 'geliefert']]));
    }

    public function test_reference_date_field_stays_a_required_date(): void
    {
        $table = $this->invoices();
        $date = $table->fields->firstWhere('name', 'datum');

        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($date, ['is_required' => false]));
        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($date->fresh(), ['type' => 'string', 'options' => ['length' => 20]]));

        $this->service()->updateField($date->fresh(), ['name' => 'rechnungsdatum']);
        $this->assertSame('rechnungsdatum', CustomField::query()->findOrFail($this->fieldId('rechnungen', 'nummer'))->optionsArray()[NumberPattern::DATE_FIELD], 'Umbenennen führt den Verweis nach.');
    }

    public function test_empty_fields_change_type_to_and_from_number(): void
    {
        $table = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['name' => 'kundennummer', 'label' => 'Kundennummer', 'type' => 'string', 'is_required' => true],
        ]);
        $field = $table->fields->firstWhere('name', 'kundennummer');

        $this->service()->updateField($field, ['type' => 'number', 'options' => [NumberPattern::PATTERN => 'K-{00000}']]);
        $field = $field->fresh();
        $this->assertSame(FieldType::Number, $field->type);
        $this->assertTrue($field->is_unique);
        $this->assertFalse($field->is_required, 'Pflicht entfällt: SAFT vergibt die Nummer.');
        $this->assertSame('K-00001', $this->writer()->create($table->fresh(), ['name' => 'Muster AG'])->kundennummer);

        // Zurück zu Text: nur ohne Werte, dann entfallen die Zähler.
        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($field, ['type' => 'string', 'options' => ['length' => 60]]));
        DynamicRecord::forTable($table->fresh())->newQuery()->get()->each(fn (DynamicRecord $r) => $this->writer()->forceDelete($r));
        $this->service()->updateField($field->fresh(), ['type' => 'string', 'options' => ['length' => 60]]);

        $this->assertSame(FieldType::String, $field->fresh()->type);
        $this->assertNull(app(NumberSequences::class)->current($field->id, NumberPattern::NO_PERIOD));
    }

    public function test_fields_with_values_keep_their_type(): void
    {
        $table = $this->invoices();
        $this->create($table, ['betreff' => 'Kurs']);

        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($table->fields->firstWhere('name', 'betreff'), ['type' => 'number', 'options' => [NumberPattern::PATTERN => '{#}']]));
        $this->assertRejected('dynamic.schema', fn () => $this->service()->updateField($table->fields->firstWhere('name', 'nummer'), ['type' => 'string', 'options' => ['length' => 60]]));
    }

    public function test_dropping_the_field_forgets_its_counters(): void
    {
        $table = $this->invoices();
        $this->create($table);
        $id = $this->fieldId('rechnungen', 'nummer');

        $field = CustomField::query()->findOrFail($id);
        $this->service()->updateField($field, ['is_active' => false]);
        $this->service()->dropField($field->fresh(), 'nummer');

        $this->assertNull(app(NumberSequences::class)->current($id, '2026'));
    }

    public function test_saft_prefix_is_reserved_for_system_tables(): void
    {
        $this->assertRejected('dynamic.invalid_identifier', fn () => $this->service()->createTable(['name' => 'saft_eigene', 'label' => 'X']));
    }

    public function test_record_form_assigns_the_number_and_ignores_input(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->invoices();

        Livewire::test(DynamicDataPage::class, ['table' => 'rechnungen'])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->setActionData(['datum' => '2026-11-20', 'betreff' => 'Kurs', 'nummer' => 'X-1'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('R-2026-0001', DynamicRecord::forTable($table)->newQuery()->where('betreff', 'Kurs')->value('nummer'));
    }

    public function test_field_editor_creates_a_number_field(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date', 'is_required' => true],
        ]);

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'label' => ['de' => 'Bestellnummer'], 'name' => 'bestellnummer', 'type' => 'number',
                'options' => [NumberPattern::PATTERN => 'B{YY}{MM}-{000}', NumberPattern::RESET => 'monthly', NumberPattern::DATE_FIELD => 'datum', NumberPattern::START => 1],
            ])
            ->assertHasNoFormErrors();

        $field = CustomField::query()->where('name', 'bestellnummer')->sole();
        $this->assertTrue($field->is_unique);
        $this->assertSame('monthly', $field->optionsArray()[NumberPattern::RESET]);
        $this->assertSame('B2611-001', $this->writer()->create($table->fresh(), ['datum' => '2026-11-03'])->bestellnummer);
    }

    public function test_field_editor_rejects_an_invalid_pattern(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->invoices();

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'label' => ['de' => 'Mahnnummer'], 'name' => 'mahnnummer', 'type' => 'number',
                'options' => [NumberPattern::PATTERN => 'M-{0000}', NumberPattern::RESET => 'yearly', NumberPattern::START => 1],
            ]);

        $this->assertFalse(CustomField::query()->where('name', 'mahnnummer')->exists(), 'Jährlich ohne Jahr: abgelehnt.');
    }

    public function test_import_keeps_given_numbers_and_assigns_missing_ones(): void
    {
        $this->actingAs($this->superAdmin());
        $table = $this->service()->updateTable($this->invoices(), ['import_enabled' => true]);
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, "Datum;Betreff;Nummer\n2026-11-20;Alt;R-2026-0010\n2026-11-21;Neu;\n");

        Storage::fake('local');
        $this->assertSame(WorkItem::DONE, $this->importRecords($table, $path, 'rechnungen.csv')->status);
        unlink($path);

        $this->assertSame(['Alt' => 'R-2026-0010', 'Neu' => 'R-2026-0011'], DynamicRecord::forTable($table)->newQuery()->orderBy('id')->pluck('nummer', 'betreff')->all());
    }

    public function test_copy_gets_a_new_number(): void
    {
        $table = $this->invoices();
        $original = $this->create($table, ['betreff' => 'Original']);

        $copy = app(RecordCopier::class)->copy($table, $original, RecordCopier::values($table, $original))['record'];

        $this->assertSame('R-2026-0002', $copy->nummer);
        $this->assertSame('Original', $copy->betreff);
    }

    public function test_assistant_proposal_derives_the_restart_from_the_pattern(): void
    {
        $form = TableProposalSchema::toFormField(['name' => 'nr', 'type' => 'number', 'formula' => 'M-{YY}{MM}-{00}', 'unique' => false]);
        $this->assertSame([NumberPattern::PATTERN => 'M-{YY}{MM}-{00}', NumberPattern::RESET => 'monthly'], $form['options']);
        $this->assertTrue($form['is_unique']);

        $this->assertSame('yearly', TableProposalSchema::toFormField(['type' => 'number', 'formula' => 'R-{YYYY}-{0000}'])['options'][NumberPattern::RESET]);
        $this->assertSame('never', TableProposalSchema::toFormField(['type' => 'number', 'formula' => 'K{#}'])['options'][NumberPattern::RESET]);
    }
}
