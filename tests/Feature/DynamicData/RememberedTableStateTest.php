<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Filament\Pages\DynamicDataPage;
use App\Models\BulkChange;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\UserTableColumn;
use App\Models\UserTableState;
use Filament\Actions\Testing\TestAction;
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
        // Ohne Adressparameter: Livewire behält die von withQueryParams() für spätere Aufrufe im selben Test.
        return Livewire::withQueryParams([])->test(DynamicDataPage::class, ['table' => 'projekte']);
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

    public function test_column_order_is_remembered_and_reset(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $page = $this->page();
        $state = $page->get('tableColumns');
        $names = array_column($state, 'name');
        // Wie das Spalten-Menü nach dem Ziehen (wasReordered).
        $page->call('applyTableColumnManager', array_reverse($state), true);

        // Neue Sitzung (neu anmelden): dieselbe Reihenfolge, auch in der angezeigten Tabelle.
        $this->flushSession();
        $fresh = $this->page();
        $this->assertSame(array_reverse($names), array_column($fresh->get('tableColumns'), 'name'));
        $this->assertSame(array_reverse($names), array_values(array_intersect(array_keys($fresh->instance()->getTable()->getColumns()), $names)));
        $this->assertSame(array_reverse($names), UserTableColumn::orderFor($admin->id, $this->page()->instance()->tableColumnsPreferenceKey()));

        // Vorgabe wieder hergestellt: kein Eintrag mehr.
        $this->page()->call('applyTableColumnManager', $state);
        $this->assertNull(UserTableColumn::orderFor($admin->id, $this->page()->instance()->tableColumnsPreferenceKey()));
        $this->assertSame($names, array_column($this->page()->get('tableColumns'), 'name'));
    }

    /**
     * LIS-19 auf GitHub (27.11.2026): Eine gleichzeitige Anfrage (z. B. ein Widget des Dashboards nach der Anmeldung)
     * schreibt ihren älteren Stand der Sitzung zurück und wirft dabei die Werte der Tabelle hinaus. Die Liste zeigt die
     * Suche weiter; „Ansicht zurücksetzen“ muss bleiben, und die gemerkte Ansicht darf nicht gelöscht werden.
     */
    public function test_a_concurrent_request_that_drops_the_session_values_keeps_the_view(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $this->page()->searchTable('alp');
        $this->flushSession();

        $page = $this->page()->assertSet('tableSearch', 'alp')->assertActionVisible(TestAction::make('resetTableView')->table());

        $this->assertTrue(session()->has('filament.tables'));
        session()->forget(['filament.tables', 'saft.table_state']);

        $page->call('$refresh')
            ->assertSet('tableSearch', 'alp')
            ->assertActionVisible(TestAction::make('resetTableView')->table());

        $this->assertSame('alp', UserTableState::stateFor($admin->id, $page->instance()->rememberedTableKey())['search'] ?? null, 'Gemerkte Ansicht bleibt gespeichert.');
    }

    /** Kopf einer Sammeländerung über Alpha (B-170), als angemeldete Person. */
    private function bulkHead(): BulkChange
    {
        return BulkChange::recordRun('bulk:test', $this->projekte, BulkChange::UPDATE, [$this->alpha->getKey()], ['anzahl' => ['op' => 'set', 'value' => 3]]);
    }

    /** Vorübergehende Ansicht (B-170): eigener Stand, nichts gespeichert, Beenden stellt den gespeicherten wieder her. */
    public function test_transient_view_is_not_saved_and_ending_it_restores_the_saved_view(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $key = $this->page()->filterTable('status', ['erledigt'])->sortTable('anzahl', 'desc')->instance()->rememberedTableKey();
        $saved = UserTableState::stateFor($admin->id, $key);

        $transient = Livewire::withQueryParams(['bulk' => $this->bulkHead()->id])->test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSet('transientTableView', true)
            ->assertSet('tableSort', 'anzahl:desc')
            ->assertCanSeeTableRecords([$this->alpha])
            ->assertCanNotSeeTableRecords([$this->beta])
            ->assertActionVisible('resetTableView')
            ->searchTable('Alp')
            ->filterTable('status', ['offen']);

        $this->assertSame($saved, UserTableState::stateFor($admin->id, $key), 'In der vorübergehenden Ansicht wird nichts gespeichert.');

        $transient->call('endTransientTableView')->assertRedirect(DynamicDataPage::urlFor('projekte'));

        $this->page()
            ->assertSet('transientTableView', false)
            ->assertSet('tableSearch', '')
            ->assertCanSeeTableRecords([$this->beta])
            ->assertCanNotSeeTableRecords([$this->alpha]);
    }

    public function test_leaving_a_transient_view_restores_the_saved_view(): void
    {
        $this->actingAs($this->superAdmin());
        $this->page()->filterTable('status', ['erledigt']);

        Livewire::withQueryParams(['bulk' => $this->bulkHead()->id])->test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertCanSeeTableRecords([$this->alpha]);

        // Ohne „Beenden“ zu einer anderen Seite und zurück: die Liste zeigt wieder den gespeicherten Stand.
        $this->page()
            ->assertSet('transientTableView', false)
            ->assertCanSeeTableRecords([$this->beta])
            ->assertCanNotSeeTableRecords([$this->alpha]);
    }

    public function test_remove_all_filters_and_reset_end_the_transient_view(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $key = $this->page()->searchTable('Bet')->instance()->rememberedTableKey();
        $head = $this->bulkHead();
        $open = fn () => Livewire::withQueryParams(['bulk' => $head->id])->test(DynamicDataPage::class, ['table' => 'projekte']);

        $open()->call('removeTableFilters')->assertRedirect(DynamicDataPage::urlFor('projekte'));
        // Das ✕ am Kärtchen (Filament ruft removeTableFilter mit dem Namen des Filters).
        $open()->call('removeTableFilter', '_bulk_change')->assertRedirect(DynamicDataPage::urlFor('projekte'));
        $open()->callTableAction('resetTableView')->assertRedirect(DynamicDataPage::urlFor('projekte'));

        $this->assertSame('Bet', UserTableState::stateFor($admin->id, $key)['search'] ?? null, '„Zurücksetzen“ beendet nur die vorübergehende Ansicht.');
    }
}
