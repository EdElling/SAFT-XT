<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/** Vorgabe aus dem Master-Datensatz, z. B. Einzelpreis ← Artikel → Preis (Kopie beim Erfassen). */
class CopyFromMasterTest extends DynamicDataTestCase
{
    private CustomTable $items;

    private CustomTable $lines;

    private DynamicRecord $screw;

    private DynamicRecord $nut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->items = $this->service()->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
        $this->lines = $this->service()->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'einzelpreis', 'label' => 'Einzelpreis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2, CopySource::OPTION => 'artikel_id.preis']],
            ['name' => 'text', 'label' => 'Text', 'type' => 'text', 'options' => [CopySource::OPTION => 'artikel_id.bezeichnung']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);

        $this->screw = DynamicRecord::forTable($this->items)->create(['bezeichnung' => 'Schraube', 'preis' => 0.35]);
        $this->nut = DynamicRecord::forTable($this->items)->create(['bezeichnung' => 'Mutter', 'preis' => 0.20]);
    }

    private function field(CustomTable $table, string $name): CustomField
    {
        return $table->fresh('fields')->fields->firstWhere('name', $name);
    }

    public function test_sources_must_fit_the_field(): void
    {
        $this->assertSame(['artikel_id.preis' => 'Artikel → Preis'], CopySource::options($this->lines, FieldType::Decimal));
        $this->assertSame(['artikel_id.bezeichnung' => 'Artikel → Bezeichnung'], CopySource::options($this->lines, FieldType::Text));

        foreach ([
            'Text in Zahl' => ['einzelpreis', [CopySource::OPTION => 'artikel_id.bezeichnung', 'precision' => 10, 'scale' => 2]],
            'Unbekannte Verknüpfung' => ['einzelpreis', [CopySource::OPTION => 'lieferant_id.preis', 'precision' => 10, 'scale' => 2]],
            'Fortlaufend und Übernahme' => ['menge', [CopySource::OPTION => 'artikel_id.preis', SequenceSuggester::OPTION => true]],
        ] as $case => [$name, $options]) {
            try {
                $this->service()->updateField($this->field($this->lines, $name), ['options' => $options]);
                $this->fail("Angenommen: {$case}");
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_empty_fields_are_copied_when_saving_and_stay_a_copy(): void
    {
        $line = DynamicRecord::forTable($this->lines->fresh('fields'))->create(['artikel_id' => $this->screw->id, 'menge' => 4]);
        $this->assertSame(['0.35', 'Schraube'], [(string) $line->einzelpreis, $line->text]);

        $own = DynamicRecord::forTable($this->lines->fresh('fields'))->create(['artikel_id' => $this->screw->id, 'einzelpreis' => 0.30]);
        $this->assertSame('0.30', (string) $own->einzelpreis);

        // Spätere Preisänderung am Artikel wirkt nicht zurück.
        $this->screw->update(['preis' => 0.50]);
        $this->assertSame('0.35', (string) $line->fresh()->einzelpreis);
    }

    public function test_form_copies_on_choice_and_change_but_keeps_own_input(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->setActionData(['artikel_id' => $this->screw->id])
            ->assertSchemaStateSet(['einzelpreis' => '0.35', 'text' => 'Schraube'], 'mountedActionSchema0')
            ->setActionData(['artikel_id' => $this->nut->id])
            ->assertSchemaStateSet(['einzelpreis' => '0.20', 'text' => 'Mutter'], 'mountedActionSchema0')
            ->setActionData(['einzelpreis' => '0.18'])
            ->setActionData(['artikel_id' => $this->screw->id])
            ->assertSchemaStateSet(['einzelpreis' => '0.18', 'text' => 'Schraube'], 'mountedActionSchema0');
    }

    public function test_detail_table_below_the_master_fills_in_immediately(): void
    {
        $this->actingAs($this->superAdmin());
        $this->service()->updateTable($this->items, ['detail_field_ids' => [$this->fieldId('positionen', 'artikel_id')]]);

        Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'artikel',
            'parentId' => $this->nut->id,
            'relationFieldId' => $this->fieldId('positionen', 'artikel_id'),
            'level' => 1,
        ])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertSchemaStateSet(['einzelpreis' => '0.20', 'text' => 'Mutter'], 'mountedActionSchema0');
    }

    public function test_field_changes_carry_over_or_are_blocked(): void
    {
        $this->service()->updateField($this->field($this->items, 'preis'), ['name' => 'listenpreis']);
        $this->assertSame('artikel_id.listenpreis', $this->field($this->lines, 'einzelpreis')->optionsArray()[CopySource::OPTION]);

        $this->service()->updateField($this->field($this->lines, 'artikel_id'), ['name' => 'produkt_id']);
        $this->assertSame('produkt_id.listenpreis', $this->field($this->lines, 'einzelpreis')->optionsArray()[CopySource::OPTION]);

        // Quellfeld: unpassender Typ und Löschen gesperrt.
        try {
            $this->service()->updateField($this->field($this->items, 'listenpreis'), ['type' => 'string', 'options' => ['length' => 50]]);
            $this->fail('Typwechsel trotz Übernahme.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('positionen.einzelpreis', $e->getMessage());
        }

        $this->service()->updateField($this->field($this->items, 'listenpreis'), ['is_active' => false]);

        try {
            $this->service()->dropField($this->field($this->items, 'listenpreis'), 'listenpreis');
            $this->fail('Quellfeld gelöscht.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        // Verknüpfung gelöscht: Übernahme entfällt.
        $this->service()->updateField($this->field($this->lines, 'produkt_id'), ['is_active' => false]);
        $this->service()->dropField($this->field($this->lines, 'produkt_id'), 'produkt_id');
        $this->assertArrayNotHasKey(CopySource::OPTION, $this->field($this->lines, 'einzelpreis')->optionsArray());
    }
}
