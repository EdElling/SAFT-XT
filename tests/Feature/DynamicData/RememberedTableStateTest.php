<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\UserTableColumn;
use App\Models\UserTableState;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** B-027: Filter, Sortierung, Suche und Zeilen pro Seite je Person und Ansicht dauerhaft. */
class RememberedTableStateTest extends DynamicDataTestCase
{
    private CustomTable $projekte;

    private DynamicRecord $alpha;

    private DynamicRecord $beta;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->projekte = $this->service()->createTable(['name' => 'projekte', 'label' => 'Projekte'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen'], ['value' => 'erledigt']]]],
            ['name' => 'anzahl', 'label' => 'Anzahl', 'type' => 'integer'],
        ]);
        $this->alpha = DynamicRecord::forTable($this->projekte)->create(['titel' => 'Alpha', 'status' => 'offen', 'anzahl' => 3]);
        $this->beta = DynamicRecord::forTable($this->projekte)->create(['titel' => 'Beta', 'status' => 'erledigt', 'anzahl' => 7]);
    }

    private function page(): Testable
    {
        return Livewire::test(DynamicDataPage::class, ['table' => 'projekte']);
    }

    public function test_view_survives_a_new_session(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $this->page()->assertActionHidden('resetTableView')
            ->filterTable('status', ['erledigt'])
            ->sortTable('anzahl', 'asc')
            ->set('tableRecordsPerPage', 25);

        $this->assertSame(1, UserTableState::query()->where('user_id', $admin->id)->count(), 'Ein Eintrag je Person und Ansicht.');

        $this->flushSession();
        $this->page()
            ->assertSet('tableSort', 'anzahl:asc')
            ->assertSet('tableRecordsPerPage', 25)
            ->assertCanSeeTableRecords([$this->beta])
            ->assertCanNotSeeTableRecords([$this->alpha]);
    }

    public function test_search_and_reset(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $this->page()->searchTable('alp');
        $this->flushSession();

        $this->page()
            ->assertSet('tableSearch', 'alp')
            ->assertCanNotSeeTableRecords([$this->beta])
            ->callTableAction('resetTableView')
            ->assertSet('tableSearch', '')
            ->assertCanSeeTableRecords([$this->alpha, $this->beta]);

        $this->assertFalse(UserTableState::query()->where('user_id', $admin->id)->exists(), 'Zurückgesetzt: nichts mehr gespeichert.');
        $this->flushSession();
        $this->page()->assertSet('tableSearch', '');
    }

    public function test_only_deviations_are_saved_and_each_person_has_their_own(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $this->page()->assertCanSeeTableRecords([$this->alpha, $this->beta]);
        $this->assertSame(0, UserTableState::query()->count(), 'Nur ansehen speichert nichts.');

        $this->page()->searchTable('alp');
        $this->actingAs($other = $this->superAdmin());
        $this->flushSession();
        $this->page()->assertSet('tableSearch', '')->assertCanSeeTableRecords([$this->beta]);
        $this->assertFalse(UserTableState::query()->where('user_id', $other->id)->exists());
    }

    public function test_session_state_wins_and_each_view_is_separate(): void
    {
        $this->actingAs($this->superAdmin());
        $this->page()->searchTable('alp');
        $this->page()->searchTable('bet');

        // Ohne neue Sitzung gilt der Stand der Sitzung (z. B. nach „Bearbeiten“ und zurück).
        $this->page()->assertSet('tableSearch', 'bet');

        // Neue Sitzung: gespeicherter Stand; eine Ansicht über einen anderen Menüeintrag (?view=…) hat ihren eigenen.
        $this->flushSession();
        $this->page()->assertSet('tableSearch', 'bet');
        $this->flushSession();
        Livewire::withQueryParams(['view' => 999])->test(DynamicDataPage::class, ['table' => 'projekte'])->assertSet('tableSearch', '');
    }

    public function test_reset_also_restores_the_columns(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $page = $this->page();
        $state = array_map(static fn (array $item): array => $item['name'] === 'anzahl' ? [...$item, 'isToggled' => false] : $item, $page->get('tableColumns'));
        $page->call('applyTableColumnManager', $state)
            ->assertActionVisible('resetTableView');
        $this->assertTrue($page->instance()->isTableColumnToggledHidden('anzahl'));
        $this->assertTrue(UserTableColumn::query()->where('user_id', $admin->id)->exists(), 'Spaltenauswahl gespeichert.');

        $page->callTableAction('resetTableView')->assertActionHidden('resetTableView');
        $this->assertFalse($page->instance()->isTableColumnToggledHidden('anzahl'), 'Spalte wieder sichtbar.');
        $this->assertFalse(UserTableColumn::query()->where('user_id', $admin->id)->exists(), 'Wie „Zurücksetzen“ im Spaltenmenü.');
    }

    public function test_removed_field_does_not_break_the_view(): void
    {
        $this->actingAs($this->superAdmin());
        $this->page()->filterTable('status', ['erledigt'])->sortTable('anzahl', 'desc');

        foreach (['anzahl', 'status'] as $name) {
            $field = $this->projekte->fields()->where('name', $name)->sole();
            $this->service()->updateField($field, ['is_active' => false]);
            $this->service()->dropField($field->fresh(), $name);
        }
        $this->flushSession();

        $this->page()->assertOk()->assertCanSeeTableRecords([$this->alpha, $this->beta]);
    }
}
