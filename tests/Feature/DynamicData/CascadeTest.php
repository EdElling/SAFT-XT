<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Cascade\CascadePath;
use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Support\RecordLink;
use App\Livewire\DynamicDetailTable;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class CascadeTest extends DynamicDataTestCase
{
    private CustomTable $kunden;

    private CustomTable $bestellungen;

    private CustomTable $positionen;

    private DynamicRecord $kunde;

    private DynamicRecord $bestellung;

    private DynamicRecord $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->bestellungen = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'rechnung_an_id', 'label' => 'Rechnung an', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        $this->positionen = $this->service()->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer'], 'is_required' => true],
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string', 'is_required' => true],
        ]);

        // Details sind Eigenschaft der Tabelle (Table-Designer).
        $this->service()->updateTable($this->kunden, ['detail_field_ids' => [$this->fieldId('bestellungen', 'kunde_id')]]);
        $this->service()->updateTable($this->bestellungen, ['detail_field_ids' => [$this->fieldId('positionen', 'bestellung_id')]]);

        $this->kunde = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Schmidt AG']);
        $this->bestellung = DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-7', 'kunde_id' => $this->kunde->id]);
        $this->position = DynamicRecord::forTable($this->positionen)->create(['bestellung_id' => $this->bestellung->id, 'artikel' => 'Schraube']);
    }

    private function detailTable(string $parent, int $parentId, string $childTable, string $fk, int $level = 1, ?int $selected = null)
    {
        return Livewire::test(DynamicDetailTable::class, [
            'parentTable' => $parent,
            'parentId' => $parentId,
            'relationFieldId' => $this->fieldId($childTable, $fk),
            'level' => $level,
            'selectedId' => $selected,
        ]);
    }

    // --- Auswahlpfad -------------------------------------------------------------------

    public function test_cascade_path_parsing_and_selection(): void
    {
        $path = CascadePath::parse('5,18:7,23');
        $this->assertSame(5, $path->root);
        $this->assertSame(7, $path->selectedAt(1));
        $this->assertSame(23, $path->fieldAt(2));
        $this->assertNull($path->selectedAt(2));

        $this->assertSame('5,18:9', $path->select(1, 18, 9)->toString(), 'Tiefere Ebenen entfallen bei neuer Auswahl.');
        $this->assertSame('5,18', $path->select(1, 18, 7)->toString(), 'Erneuter Klick hebt die Auswahl auf.');
        $this->assertNull($path->selectRoot(5)->toString());
        $this->assertSame('6', $path->selectRoot(6)->toString());
        $this->assertSame(5, CascadePath::parse('5,abc,1:2')->root);
        $this->assertSame([], CascadePath::parse('5,abc,1:2')->segments);
        $this->assertNull(CascadePath::parse('x')->root);
    }

    // --- Kaskade auf einer Seite -------------------------------------------------------

    public function test_click_in_list_shows_record_and_its_details_below(): void
    {
        $this->actingAs($this->superAdmin());

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])
            ->call('selectRecord', $this->kunde->id)
            ->assertSet('selection', (string) $this->kunde->id)
            ->assertSee('Kunden: Schmidt AG')
            ->assertSeeHtml('data-field-blocks')
            ->assertSeeLivewire(DynamicDetailTable::class);

        // Name und Wert nebeneinander (dt/dd) statt untereinander.
        $this->assertMatchesRegularExpression('#<dt>[^<]+</dt>\s*<dd[^>]*>\s*Schmidt AG\s*</dd>#', $page->html());
    }

    public function test_second_level_appears_below_when_detail_row_is_selected(): void
    {
        $this->actingAs($this->superAdmin());
        $fk = $this->fieldId('bestellungen', 'kunde_id');

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])
            ->call('selectRecord', $this->kunde->id)
            ->dispatch('cascade-select', level: 1, fieldId: $fk, recordId: $this->bestellung->id);

        $page->assertSet('selection', "{$this->kunde->id},{$fk}:{$this->bestellung->id}")
            ->assertSee('Bestellungen: B-7');

        // Gleiche Ansicht wie beim Einstieg über „Bestellungen“: Positionen erscheinen.
        $this->detailTable('bestellungen', $this->bestellung->id, 'positionen', 'bestellung_id', 2)
            ->assertSee('Schraube');
    }

    public function test_detail_row_click_dispatches_selection_and_highlights(): void
    {
        $this->actingAs($this->superAdmin());

        $table = $this->detailTable('kunden', $this->kunde->id, 'bestellungen', 'kunde_id', 1, $this->bestellung->id)
            ->call('selectRecord', $this->bestellung->id)
            ->assertDispatched('cascade-select', level: 1, fieldId: $this->fieldId('bestellungen', 'kunde_id'), recordId: $this->bestellung->id);

        $this->assertStringContainsString('saft-row-selected', $table->html());
    }

    public function test_url_selection_is_restored_on_reload(): void
    {
        $this->actingAs($this->superAdmin());
        $fk = $this->fieldId('bestellungen', 'kunde_id');

        $this->get(DynamicDataPage::urlFor('kunden', $this->kunde->id)."%2C{$fk}%3A{$this->bestellung->id}")
            ->assertOk()
            ->assertSee('Kunden: Schmidt AG')
            ->assertSee('Bestellungen: B-7');
    }

    public function test_manipulated_path_is_truncated(): void
    {
        $this->actingAs($this->superAdmin());
        $fremderKunde = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Fremd']);
        $fk = $this->fieldId('bestellungen', 'kunde_id');

        // Bestellung gehört nicht zum ausgewählten Kunden -> Auswahl entfällt, Reiter bleibt.
        Livewire::withQueryParams(['sel' => "{$fremderKunde->id},{$fk}:{$this->bestellung->id}"])
            ->test(DynamicDataPage::class, ['table' => 'kunden'])
            ->assertSet('selection', "{$fremderKunde->id},{$fk}")
            ->assertDontSee('Bestellungen: B-7');

        // Verknüpfung, die für Kunden nicht als Detail konfiguriert ist -> abgeschnitten.
        $rechnung = $this->fieldId('bestellungen', 'rechnung_an_id');
        Livewire::withQueryParams(['sel' => "{$this->kunde->id},{$rechnung}:{$this->bestellung->id}"])
            ->test(DynamicDataPage::class, ['table' => 'kunden'])
            ->assertSet('selection', (string) $this->kunde->id);
    }

    public function test_max_depth_is_respected(): void
    {
        $this->assertSame(CascadePath::MAX_LEVELS - 1, count(CascadePath::parse('1,2:3,4:5,6:7,8:9,10:11')->segments));
    }

    public function test_multiple_details_on_one_level_are_tabs(): void
    {
        $this->actingAs($this->superAdmin());
        $this->service()->updateTable($this->kunden->fresh(), ['detail_field_ids' => [
            $this->fieldId('bestellungen', 'kunde_id'),
            $this->fieldId('bestellungen', 'rechnung_an_id'),
        ]]);

        Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])
            ->call('selectRecord', $this->kunde->id)
            ->assertSee('role="tab"', escape: false);
    }

    /** Je Klick wurde dieselbe Tabelle je Zeile und Zelle neu geladen (Lager-Demo: 462 Abfragen je Klick, 25.11.2026). */
    public function test_row_click_loads_each_custom_table_once(): void
    {
        $this->actingAs($this->superAdmin());
        $this->service()->updateTable($this->kunden->fresh(), ['detail_field_ids' => [
            $this->fieldId('bestellungen', 'kunde_id'),
            $this->fieldId('bestellungen', 'rechnung_an_id'),
        ]]);
        for ($i = 0; $i < 5; $i++) {
            DynamicRecord::forTable($this->bestellungen)->create(['nummer' => "B-$i", 'kunde_id' => $this->kunde->id, 'rechnung_an_id' => $this->kunde->id]);
        }
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])->call('selectRecord', $this->kunde->id);

        $byName = 0;
        DB::listen(function (QueryExecuted $query) use (&$byName): void {
            if (str_contains($query->sql, 'custom_tables') && str_contains($query->sql, '"name" = ?')) {
                $byName++;
            }
        });
        $page->call('selectRecord', $this->kunde->id);

        $this->assertLessThanOrEqual(3, $byName, 'Tabellen des Table-Designers je Klick höchstens einmal je Name laden (CustomTableLookup).');
    }

    // --- Rechte -----------------------------------------------------------------------

    public function test_details_require_permission_on_child_table(): void
    {
        $this->actingAs($this->userWithPermissions(['data.kunden.view']));

        Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])
            ->call('selectRecord', $this->kunde->id)
            ->assertSee('Kunden: Schmidt AG')
            ->assertDontSeeLivewire(DynamicDetailTable::class);

        $this->detailTable('kunden', $this->kunde->id, 'bestellungen', 'kunde_id')->assertForbidden();
    }

    public function test_detail_component_only_for_configured_relations(): void
    {
        $this->actingAs($this->superAdmin());

        $this->detailTable('kunden', $this->kunde->id, 'bestellungen', 'rechnung_an_id')->assertNotFound();
    }

    // --- Lesend, Bearbeiten im Popup --------------------------------------------------

    public function test_rows_are_read_only_and_details_popup_offers_edit(): void
    {
        $this->actingAs($this->superAdmin());

        $this->detailTable('bestellungen', $this->bestellung->id, 'positionen', 'bestellung_id')
            ->assertActionDoesNotExist(TestAction::make('edit')->table($this->position))
            ->mountAction(TestAction::make('details')->table($this->position))
            ->assertMountedActionModalSee('Schraube');

        $this->detailTable('bestellungen', $this->bestellung->id, 'positionen', 'bestellung_id')
            ->callAction([TestAction::make('details')->table($this->position), TestAction::make('edit')], ['artikel' => 'Mutter'])
            ->assertHasNoFormErrors();

        $this->assertSame('Mutter', $this->position->fresh()->artikel);
        $this->assertSame($this->bestellung->id, $this->position->fresh()->bestellung_id, 'Verknüpfung bleibt gesperrt.');
    }

    public function test_new_detail_record_is_linked_to_master(): void
    {
        $this->actingAs($this->superAdmin());
        $other = DynamicRecord::forTable($this->bestellungen)->create(['nummer' => 'B-9']);

        $this->detailTable('bestellungen', $this->bestellung->id, 'positionen', 'bestellung_id')
            ->callAction(TestAction::make(CreateAction::class)->table(), ['artikel' => 'Neu', 'bestellung_id' => $other->id])
            ->assertHasNoFormErrors();

        $this->assertSame($this->bestellung->id, DynamicRecord::forTable($this->positionen)->newQuery()->where('artikel', 'Neu')->value('bestellung_id'));
    }

    public function test_delete_in_popup_respects_delete_guard(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'bestellungen'])
            ->callAction([TestAction::make('details')->table($this->bestellung), TestAction::make('delete')]);

        Notification::assertNotified(__('errors.titles.business_rule'));
        $this->assertFalse($this->bestellung->fresh()->trashed());

        try {
            $this->bestellung->delete();
            $this->fail('Löschsperre erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertSame('dynamic.has_details', $e->errorCode);
        }
    }

    // --- Konfiguration im Table-Designer, Links ---------------------------------------

    public function test_details_are_configured_per_table_in_designer(): void
    {
        $this->actingAs($this->superAdmin());
        $rechnung = $this->fieldId('bestellungen', 'rechnung_an_id');

        Livewire::test(EditCustomTable::class, ['record' => $this->kunden->getRouteKey()])
            ->assertFormFieldExists('detail_field_ids', fn ($f): bool => array_keys($f->getOptions()) === [$this->fieldId('bestellungen', 'kunde_id'), $rechnung])
            ->fillForm(['detail_field_ids' => [(string) $rechnung]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$rechnung], $this->kunden->fresh()->detail_field_ids);
    }

    public function test_activity_log_and_preview_link_open_list_with_selection(): void
    {
        $this->actingAs($this->superAdmin());
        // Eintrag ohne eigene Spaltenauswahl: Der Link braucht keine Ansicht, sonst wäre er nicht markiert.
        (new DataMenu)->ensure($this->bestellungen);
        $activity = Activity::query()->where('subject_type', 'dynamic:bestellungen')->where('subject_id', $this->bestellung->id)->firstOrFail();

        $this->assertSame(DynamicDataPage::urlFor('bestellungen', $this->bestellung->id), RecordLink::forActivity($activity));
    }

    public function test_migration_moves_menu_details_to_table(): void
    {
        $kundenMenu = (new DataMenu)->ensure($this->kunden);
        $kundenMenu->update(['view_config' => ['columns' => [1], 'details' => [$this->fieldId('bestellungen', 'rechnung_an_id')]]]);
        Schema::table('custom_tables', fn ($t) => $t->dropColumn('detail_field_ids'));

        (require database_path('migrations/0001_01_01_000023_move_detail_configuration_to_custom_tables.php'))->up();

        $this->assertSame([$this->fieldId('bestellungen', 'rechnung_an_id')], $this->kunden->fresh()->detail_field_ids);
        $this->assertSame(['columns' => [1]], $kundenMenu->fresh()->view_config);
    }
}
