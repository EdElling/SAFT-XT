<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\RelationDisplay;
use App\DynamicData\Ui\DynamicFormBuilder;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\DynamicData\Ui\DynamicValueFormatter;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/** Anzeige einer Verknüpfung aus mehreren Feldern mit Trennzeichen. */
class RelationDisplayTest extends DynamicDataTestCase
{
    private CustomTable $customers;

    private CustomTable $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customers = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string'],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string'],
        ]);
        $this->orders = $this->service()->createTable(['name' => 'auftraege', 'label' => 'Aufträge'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['firma', 'ort'], 'separator' => ', ']],
        ]);

        $acme = DynamicRecord::forTable($this->customers)->create(['firma' => 'ACME GmbH', 'ort' => 'Berlin']);
        $beta = DynamicRecord::forTable($this->customers)->create(['firma' => 'Beta AG', 'ort' => null]);
        DynamicRecord::forTable($this->orders)->create(['nummer' => 'A-1', 'kunde_id' => $acme->id]);
        DynamicRecord::forTable($this->orders)->create(['nummer' => 'A-2', 'kunde_id' => $beta->id]);
    }

    private function relation(): CustomField
    {
        return $this->orders->fresh('fields')->fields->firstWhere('name', 'kunde_id');
    }

    public function test_compose_skips_empty_values_and_unknown_separators(): void
    {
        $this->assertSame('Anna Keller', RelationDisplay::compose(['Anna', ' ', 'Keller'], ' '));
        $this->assertNull(RelationDisplay::compose([null, ''], ', '));
        $this->assertSame(' ', RelationDisplay::separator(['separator' => ';DROP']));
        $this->assertSame(['firma'], RelationDisplay::fields(['display_field' => 'firma']));
    }

    public function test_stored_options_keep_the_first_field_for_compatibility(): void
    {
        $options = $this->relation()->optionsArray();

        $this->assertSame(['firma', 'ort'], $options['display_fields']);
        $this->assertSame('firma', $options['display_field']);
        $this->assertSame(', ', $options['separator']);
    }

    public function test_list_details_export_and_filter_show_the_combined_value(): void
    {
        $this->actingAs($this->superAdmin());
        $field = $this->relation();
        $orders = $this->orders->fresh('fields');

        $rows = app(DynamicTableBuilder::class)->query($orders)->orderBy('nummer')->get();
        $this->assertSame(['ACME GmbH, Berlin', 'Beta AG'], $rows->map(fn (DynamicRecord $r): ?string => DynamicTableBuilder::relationLabel($field, $r))->all());
        $this->assertSame('ACME GmbH, Berlin', DynamicValueFormatter::display($field, $rows[0]));

        Livewire::test(DynamicDataPage::class, ['table' => 'auftraege'])
            ->assertSee('ACME GmbH, Berlin')
            ->assertDontSee('Beta AG,');
    }

    public function test_form_select_searches_all_display_fields(): void
    {
        $select = collect(app(DynamicFormBuilder::class)->components($this->orders->fresh('fields')))
            ->first(fn ($component): bool => $component->getName() === 'kunde_id');
        $this->assertInstanceOf(Select::class, $select);

        $model = DynamicRecord::forTableName('kunden');
        $byCity = RelationDisplay::options(RelationDisplay::search($model->newQuery(), $this->relation()->optionsArray(), 'Berl'), $this->relation()->optionsArray(), 50);
        $this->assertSame(['ACME GmbH, Berlin'], array_values($byCity));
    }

    public function test_renaming_a_display_field_updates_the_list_and_deleting_it_is_blocked(): void
    {
        $city = $this->customers->fresh('fields')->fields->firstWhere('name', 'ort');
        $this->service()->updateField($city, ['name' => 'stadt']);

        $this->assertSame(['firma', 'stadt'], $this->relation()->optionsArray()['display_fields']);

        $city = $city->fresh();
        $this->service()->updateField($city, ['is_active' => false]);

        $this->expectException(BusinessRuleException::class);
        $this->service()->dropField($city->fresh(), 'stadt');
    }

    public function test_invalid_display_fields_are_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->service()->createTable(['name' => 'falsch', 'label' => 'Falsch'], [
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['firma', 'gibt_es_nicht']]],
        ]);
    }
}
