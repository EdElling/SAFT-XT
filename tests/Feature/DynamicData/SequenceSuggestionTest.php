<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Rules\SequenceSuggester;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/** Fortlaufender Vorschlag (höchster Wert + 1), z. B. Position je Bestellung. */
class SequenceSuggestionTest extends DynamicDataTestCase
{
    private CustomTable $orders;

    private CustomTable $lines;

    private DynamicRecord $first;

    private DynamicRecord $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
        ]);
        $this->lines = $this->service()->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
            ['name' => 'position', 'label' => 'Position', 'type' => 'integer', 'options' => [SequenceSuggester::OPTION => true, SequenceSuggester::SCOPE => 'bestellung_id']],
            ['name' => 'laufnummer', 'label' => 'Laufnummer', 'type' => 'integer', 'options' => [SequenceSuggester::OPTION => '1']],
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string'],
        ]);

        $this->first = DynamicRecord::forTable($this->orders)->create(['nummer' => 'B-1']);
        $this->second = DynamicRecord::forTable($this->orders)->create(['nummer' => 'B-2']);

        foreach ([[1, 'Schraube'], [2, 'Mutter']] as [$position, $article]) {
            DynamicRecord::forTable($this->lines)->create(['bestellung_id' => $this->first->id, 'position' => $position, 'laufnummer' => $position, 'artikel' => $article]);
        }
    }

    private function field(string $name): CustomField
    {
        return $this->lines->fresh('fields')->fields->firstWhere('name', $name);
    }

    public function test_options_are_validated(): void
    {
        $this->assertSame([SequenceSuggester::OPTION => true, SequenceSuggester::SCOPE => 'bestellung_id'], $this->field('position')->optionsArray());
        $this->assertSame([SequenceSuggester::OPTION => true], $this->field('laufnummer')->optionsArray());

        $this->service()->updateField($this->field('laufnummer'), ['options' => [SequenceSuggester::OPTION => false]]);
        $this->assertSame([], $this->field('laufnummer')->optionsArray());

        $this->expectException(BusinessRuleException::class);
        $this->service()->updateField($this->field('position'), ['options' => [SequenceSuggester::OPTION => true, SequenceSuggester::SCOPE => 'artikel']]);
    }

    public function test_next_value_is_counted_per_scope_without_deleted_records(): void
    {
        $suggester = app(SequenceSuggester::class);
        $lines = $this->lines->fresh('fields');

        $this->assertSame(3, $suggester->next($lines, $this->field('position'), $this->first->id));
        $this->assertSame(1, $suggester->next($lines, $this->field('position'), $this->second->id));
        $this->assertNull($suggester->next($lines, $this->field('position'), null));
        $this->assertSame(3, $suggester->next($lines, $this->field('laufnummer')));

        DynamicRecord::forTable($lines)->newQuery()->where('position', 2)->firstOrFail()->delete();
        $this->assertSame(2, $suggester->next($lines, $this->field('position'), $this->first->id));
    }

    public function test_empty_fields_are_filled_when_saving(): void
    {
        $line = DynamicRecord::forTable($this->lines->fresh('fields'))->create(['bestellung_id' => $this->second->id, 'artikel' => 'Dübel']);
        $this->assertSame([1, 3], [(int) $line->position, (int) $line->laufnummer]);

        $own = DynamicRecord::forTable($this->lines->fresh('fields'))->create(['bestellung_id' => $this->first->id, 'position' => 10, 'artikel' => 'Nagel']);
        $this->assertSame(10, (int) $own->position);
    }

    public function test_detail_table_suggests_the_next_position_of_its_order(): void
    {
        $this->actingAs($this->superAdmin());
        $this->service()->updateTable($this->orders, ['detail_field_ids' => [$this->fieldId('positionen', 'bestellung_id')]]);

        Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'bestellungen',
            'parentId' => $this->first->id,
            'relationFieldId' => $this->fieldId('positionen', 'bestellung_id'),
            'level' => 1,
        ])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertSchemaStateSet(['position' => 3, 'laufnummer' => 3], 'mountedActionSchema0')
            ->setActionData(['artikel' => 'Unterlegscheibe'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(3, (int) DynamicRecord::forTable($this->lines->fresh('fields'))->newQuery()->where('artikel', 'Unterlegscheibe')->value('position'));
    }

    public function test_list_suggestion_follows_the_chosen_order_until_changed_by_hand(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertSchemaStateSet(['position' => null], 'mountedActionSchema0')
            ->setActionData(['bestellung_id' => $this->first->id])
            ->assertSchemaStateSet(['position' => 3], 'mountedActionSchema0')
            ->setActionData(['bestellung_id' => $this->second->id])
            ->assertSchemaStateSet(['position' => 1], 'mountedActionSchema0')
            ->setActionData(['position' => 7])
            ->setActionData(['bestellung_id' => $this->first->id])
            ->assertSchemaStateSet(['position' => 7], 'mountedActionSchema0');
    }

    public function test_relation_changes_carry_over_to_the_scope(): void
    {
        $order = $this->field('bestellung_id');
        $this->service()->updateField($order, ['name' => 'auftrag_id']);
        $this->assertSame('auftrag_id', $this->field('position')->optionsArray()[SequenceSuggester::SCOPE]);

        $this->service()->updateField($order->fresh(), ['is_active' => false]);
        $this->service()->dropField($order->fresh(), 'auftrag_id');
        $this->assertSame([SequenceSuggester::OPTION => true], $this->field('position')->optionsArray());
    }
}
