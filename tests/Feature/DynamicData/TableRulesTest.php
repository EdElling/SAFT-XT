<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Rules\Operand;
use App\DynamicData\Rules\TableRule;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\DynamicData\Rules\TableRuleValidator;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Livewire\Livewire;

/** Eigene Regeln auf Tabellenebene im Table-Designer (z. B. „Lieferdatum ≥ Bestelldatum“). */
class TableRulesTest extends DynamicDataTestCase
{
    private CustomTable $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'bestelldatum', 'label' => 'Bestelldatum', 'type' => 'date'],
            ['name' => 'lieferdatum', 'label' => 'Lieferdatum', 'type' => 'date'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen'], ['value' => 'versandt']]]],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'text'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return ['right_kind' => TableRuleNormalizer::KIND_FIELD, 'when_filled' => true, ...$row];
    }

    private function saveRules(array $rows): CustomTable
    {
        return $this->service()->updateTable($this->orders->fresh('fields'), ['validation_rules' => $rows])->fresh('fields');
    }

    private function deliveryRule(): array
    {
        return self::row(['left' => 'lieferdatum', 'operator' => '>=', 'right_field' => 'bestelldatum']);
    }

    private function quantityRule(): array
    {
        return self::row(['left' => 'menge', 'operator' => '>', 'right_kind' => TableRuleNormalizer::KIND_VALUE, 'right_value' => '0', 'message' => ['de' => 'Bitte mindestens 1 bestellen.']]);
    }

    public function test_rules_are_validated_and_stored(): void
    {
        $orders = $this->saveRules([$this->deliveryRule(), $this->quantityRule()]);

        // assertEquals: MySQL speichert JSON-Objekte mit sortierten Schlüsseln.
        $this->assertEquals([
            ['left' => ['kind' => 'field', 'field' => 'lieferdatum'], 'operator' => '>=', 'right' => ['kind' => 'field', 'field' => 'bestelldatum'], 'when_filled' => true, 'source' => 'designer'],
            ['left' => ['kind' => 'field', 'field' => 'menge'], 'operator' => '>', 'right' => ['kind' => 'value', 'value' => 0], 'when_filled' => true, 'source' => 'designer', 'message' => ['de' => 'Bitte mindestens 1 bestellen.']],
        ], $orders->validation_rules);
        $this->assertTrue(Activity::query()->where('subject_type', $orders->getMorphClass())->where('subject_id', $orders->id)->where('event', 'updated')->exists());

        // Zurück ins Formular.
        $rows = TableRuleNormalizer::toRows($orders);
        $this->assertSame('0', $rows[1]['right_value']);
        $this->assertSame('Lieferdatum ≥ Bestelldatum', TableRuleNormalizer::describe($orders->tableRules()[0], $orders));
    }

    public function test_invalid_rules_are_rejected(): void
    {
        foreach ([
            'Langer Text' => self::row(['left' => 'notiz', 'operator' => '=', 'right_field' => 'nummer']),
            'Reihenfolge bei Auswahl' => self::row(['left' => 'status', 'operator' => '<', 'right_kind' => TableRuleNormalizer::KIND_VALUE, 'right_value' => 'offen']),
            'Datum mit Zahl' => self::row(['left' => 'lieferdatum', 'operator' => '>=', 'right_field' => 'menge']),
            'Feld mit sich selbst' => self::row(['left' => 'menge', 'operator' => '>', 'right_field' => 'menge']),
            'Text als Zahl' => self::row(['left' => 'menge', 'operator' => '>', 'right_kind' => TableRuleNormalizer::KIND_VALUE, 'right_value' => 'viel']),
            'Unbekannter Auswahlwert' => self::row(['left' => 'status', 'operator' => '<>', 'right_kind' => TableRuleNormalizer::KIND_VALUE, 'right_value' => 'storniert']),
        ] as $case => $row) {
            try {
                $this->saveRules([$row]);
                $this->fail("Angenommen: {$case}");
            } catch (BusinessRuleException $e) {
                $this->assertStringStartsWith('Regel 1:', $e->getMessage(), $case);
            }
        }
    }

    public function test_designer_form_shows_and_saves_rules_and_warns_about_existing_records(): void
    {
        $this->actingAs($this->superAdmin());
        DynamicRecord::forTable($this->orders)->create(['nummer' => 'A-1', 'bestelldatum' => '2026-05-10', 'lieferdatum' => '2026-05-01', 'menge' => 3]);
        $this->saveRules([$this->deliveryRule()]);

        Livewire::test(EditCustomTable::class, ['record' => $this->orders->getRouteKey()])
            ->assertFormSet(fn (array $state): bool => array_values($state['validation_rules'])[0]['left'] === 'lieferdatum')
            ->set('data.validation_rules', [$this->deliveryRule(), $this->quantityRule()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Vorhandene Datensätze verletzen Regeln');

        $this->assertCount(2, $this->orders->fresh()->tableRules());
        $this->assertSame(1, $this->service()->ruleViolations($this->orders->fresh('fields'), $this->orders->fresh()->tableRules()[0]));
        $this->assertSame(0, $this->service()->ruleViolations($this->orders->fresh('fields'), $this->orders->fresh()->tableRules()[1]));
    }

    public function test_data_form_checks_rules_with_default_and_own_message(): void
    {
        $orders = $this->saveRules([$this->deliveryRule(), $this->quantityRule()]);
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->callTableAction('create', data: ['nummer' => 'A-2', 'bestelldatum' => '2026-05-10', 'lieferdatum' => '2026-05-01', 'menge' => 0])
            ->assertHasTableActionErrors(['lieferdatum', 'menge']);

        $fields = $orders->fields->keyBy('name')->all();
        $this->assertSame('darf nicht vor „Bestelldatum“ liegen.', TableRuleValidator::message($orders->tableRules()[0], $fields));
        $this->assertSame('Bitte mindestens 1 bestellen.', TableRuleValidator::message($orders->tableRules()[1], $fields));

        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->callTableAction('create', data: ['nummer' => 'A-3', 'bestelldatum' => '2026-05-10', 'lieferdatum' => null, 'menge' => 2])
            ->assertHasNoTableActionErrors();

        // „Nur wenn gefüllt“ aus: Leeres Lieferdatum ist ein Verstoß.
        $this->saveRules([[...$this->deliveryRule(), 'when_filled' => false]]);
        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->callTableAction('create', data: ['nummer' => 'A-4', 'bestelldatum' => '2026-05-10', 'lieferdatum' => null, 'menge' => 2])
            ->assertHasTableActionErrors(['lieferdatum']);
    }

    public function test_field_changes_respect_rules(): void
    {
        $this->saveRules([$this->deliveryRule()]);
        $delivery = $this->orders->fresh('fields')->fields->firstWhere('name', 'lieferdatum');

        $this->service()->updateField($delivery, ['name' => 'liefertermin']);
        $this->assertSame('liefertermin', $this->orders->fresh()->tableRules()[0]->left->fieldName());

        try {
            $this->service()->updateField($delivery->fresh(), ['type' => 'string', 'options' => ['length' => 50]]);
            $this->fail('Typwechsel trotz Regel.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Regel', $e->getMessage());
        }

        $this->service()->updateField($delivery->fresh(), ['is_active' => false]);
        $this->expectException(BusinessRuleException::class);
        $this->service()->dropField($delivery->fresh(), 'liefertermin');
    }

    public function test_rules_from_other_sources_are_kept(): void
    {
        $foreign = (new TableRule(Operand::field('menge'), '<=', Operand::value(100), source: TableRule::SOURCE_DATABASE))->toArray();
        $this->orders->forceFill(['validation_rules' => [$foreign]])->save();

        $orders = $this->saveRules([$this->deliveryRule()]);

        $this->assertSame([TableRule::SOURCE_DATABASE, TableRule::SOURCE_DESIGNER], array_map(fn (TableRule $r): string => $r->source, $orders->tableRules()));
        $this->assertCount(1, TableRuleNormalizer::toRows($orders));
    }
}
