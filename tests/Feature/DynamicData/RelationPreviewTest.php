<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\Filament\Pages\DynamicDataPage;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class RelationPreviewTest extends DynamicDataTestCase
{
    private CustomTable $artikel;

    private CustomTable $bestellungen;

    private CustomTable $positionen;

    private DynamicRecord $notebook;

    private DynamicRecord $bestellung;

    private DynamicRecord $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artikel = $this->service()->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'nummer', 'label' => 'Artikelnummer', 'type' => 'string', 'is_required' => true],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'is_required' => true],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
        $this->bestellungen = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true],
        ]);
        $this->positionen = $this->service()->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);

        $this->service()->updateTable($this->bestellungen, ['detail_field_ids' => [$this->fieldId('positionen', 'bestellung_id')]]);

        $this->notebook = DynamicRecord::forTable($this->artikel)->create(['nummer' => 'A-1001', 'bezeichnung' => 'Notebook 14"', 'preis' => 1249]);
        $this->bestellung = DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-1']);
        $this->position = DynamicRecord::forTable($this->positionen)->create(['bestellung_id' => $this->bestellung->id, 'artikel_id' => $this->notebook->id, 'menge' => 2]);
    }

    private function detailTable()
    {
        return Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'bestellungen',
            'parentId' => $this->bestellung->id,
            'relationFieldId' => $this->fieldId('positionen', 'bestellung_id'),
        ]);
    }

    public function test_click_on_article_in_detail_table_shows_article_data_in_place(): void
    {
        $this->actingAs($this->superAdmin());
        (new DataMenu)->ensure($this->artikel);

        $this->detailTable()
            ->mountAction(TestAction::make('preview_artikel_id')->table($this->position))
            ->assertMountedActionModalSee('Artikel: Notebook 14"')
            ->assertMountedActionModalSee('A-1001')
            ->assertMountedActionModalSee('1.249,00')
            ->assertMountedActionModalSee('Zu „Artikel“ wechseln')
            ->assertMountedActionModalSeeHtml(e(DynamicDataPage::urlFor('artikel', $this->notebook->id)));
    }

    public function test_preview_works_in_list_and_detail_tables(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->mountAction(TestAction::make('preview_artikel_id')->table($this->position))
            ->assertMountedActionModalSee('A-1001');

        // Auch in den Detailtabellen der Kaskade.
        $this->detailTable()
            ->mountAction(TestAction::make('preview_artikel_id')->table($this->position))
            ->assertMountedActionModalSee('A-1001');
    }

    public function test_without_read_permission_article_is_plain_text(): void
    {
        $this->actingAs($this->userWithPermissions(['data.positionen.view']));

        $column = Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->assertSee('Notebook 14"')
            ->instance()->getTable()->getColumn('artikel_id__label');

        $this->assertNull($column->getAction(), 'Ohne data.artikel.view keine Vorschau.');
    }

    public function test_deleted_article_is_visible_only_with_delete_permission(): void
    {
        DynamicRecord::forTable($this->positionen)->newQuery()->whereKey($this->position->id)->update(['artikel_id' => $this->notebook->id]);
        // Löschsperre umgehen: Artikel direkt als gelöscht markieren.
        DB::connection('app_data')->table($this->qualified('artikel'))->where('id', $this->notebook->id)->update(['deleted_at' => now()]);

        $this->actingAs($this->userWithPermissions(['data.positionen.view', 'data.artikel.view']));
        Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->mountAction(TestAction::make('preview_artikel_id')->table($this->position))
            ->assertMountedActionModalSee('nicht (mehr) verfügbar')
            ->assertMountedActionModalDontSee('A-1001');

        $this->actingAs($this->userWithPermissions(['data.positionen.view', 'data.artikel.view', 'data.artikel.delete']));
        Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->mountAction(TestAction::make('preview_artikel_id')->table($this->position))
            ->assertMountedActionModalSee('Dieser Datensatz ist gelöscht.')
            ->assertMountedActionModalSee('A-1001');
    }

    public function test_rendering_does_not_query_per_row(): void
    {
        $this->actingAs($this->superAdmin());
        $count = function (): int {
            DB::connection('app_data')->flushQueryLog();
            DB::connection('app_data')->enableQueryLog();
            Livewire::test(DynamicDataPage::class, ['table' => 'positionen']);

            return count(DB::connection('app_data')->getQueryLog());
        };

        $few = $count();
        for ($i = 0; $i < 10; $i++) {
            DynamicRecord::forTable($this->positionen)->create(['artikel_id' => $this->notebook->id, 'menge' => $i]);
        }

        $this->assertSame($few, $count(), 'Vorschau darf beim Rendern keine Abfragen pro Zeile erzeugen.');
    }

    public function test_empty_relation_has_no_preview(): void
    {
        $this->actingAs($this->superAdmin());
        $ohne = DynamicRecord::forTable($this->positionen)->create(['menge' => 1]);

        Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->assertActionHidden(TestAction::make('preview_artikel_id')->table($ohne));
    }
}
