<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Rules\NowDefault;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Vorgabe beim Anlegen für Datum und Datum/Zeit: „Mit heute bzw. jetzt vorbelegen“. */
class NowDefaultTest extends DynamicDataTestCase
{
    private CustomTable $movements;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 14:05:37');

        $this->service()->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
            ['name' => 'eingefuehrt', 'label' => 'Eingeführt', 'type' => 'date'],
        ]);
        $this->movements = $this->service()->createTable(['name' => 'bewegungen', 'label' => 'Bewegungen'], [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'zeitpunkt', 'label' => 'Zeitpunkt', 'type' => 'datetime', 'options' => [NowDefault::OPTION => true]],
            ['name' => 'buchungstag', 'label' => 'Buchungstag', 'type' => 'date', 'options' => [NowDefault::OPTION => '1']],
            ['name' => 'erledigt_am', 'label' => 'Erledigt am', 'type' => 'date'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_option_is_kept_only_for_date_fields_and_not_together_with_copying(): void
    {
        $fields = $this->movements->fields()->get()->keyBy('name');
        $this->assertSame([NowDefault::OPTION => true], $fields['zeitpunkt']->optionsArray());
        $this->assertSame([NowDefault::OPTION => true], $fields['buchungstag']->optionsArray());
        $this->assertSame([], $fields['erledigt_am']->optionsArray());

        $menge = $this->service()->updateField($fields['menge'], ['options' => [NowDefault::OPTION => true]]);
        $this->assertArrayNotHasKey(NowDefault::OPTION, $menge->optionsArray(), 'Nicht bei Ganzzahlen.');

        $this->expectException(BusinessRuleException::class);
        $this->service()->updateField($fields['erledigt_am'], ['options' => [NowDefault::OPTION => true, 'copy_from' => 'artikel_id.eingefuehrt']]);
    }

    public function test_new_record_dialog_is_prefilled_and_stays_editable(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'bewegungen'])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertSchemaStateSet(['zeitpunkt' => '2026-11-16 14:05:00', 'buchungstag' => '2026-11-16', 'erledigt_am' => null], 'mountedActionSchema0')
            ->setActionData(['buchungstag' => '2026-11-01', 'menge' => 3])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $record = DynamicRecord::forTable($this->movements)->newQuery()->sole();
        $this->assertStringStartsWith('2026-11-16 14:05', (string) $record->getAttribute('zeitpunkt'));
        $this->assertStringStartsWith('2026-11-01', (string) $record->getAttribute('buchungstag'), 'Geänderte Vorgabe wird gespeichert.');
    }

    public function test_saving_without_the_dialog_sets_nothing(): void
    {
        $record = DynamicRecord::forTable($this->movements)->create(['menge' => 1]);

        $this->assertNull($record->fresh()->getAttribute('zeitpunkt'));
    }
}
