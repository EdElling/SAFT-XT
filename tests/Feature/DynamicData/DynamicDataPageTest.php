<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Support\ActivityChanges;
use App\Filament\Support\RecordLink;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Navigation\NavigationBuilder;
use Livewire\Livewire;

class DynamicDataPageTest extends DynamicDataTestCase
{
    private CustomTable $kunden;

    private CustomTable $projekte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
        ]);
        $this->projekte = $this->service()->createTable(['name' => 'projekte', 'label' => 'Projekte'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'options' => ['length' => 20], 'is_required' => true, 'is_searchable' => true, 'is_sortable' => true],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_unique' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen', 'label' => 'Offen'], ['value' => 'erledigt', 'label' => 'Erledigt']]]],
            ['name' => 'budget', 'label' => 'Budget', 'type' => 'decimal', 'options' => ['precision' => 8, 'scale' => 2], 'validation_rules' => ['min' => 0]],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
            ['name' => 'intern', 'label' => 'Intern', 'type' => 'string', 'show_in_table' => false, 'show_in_form' => false],
        ]);
    }

    // --- Zugriff -----------------------------------------------------------------------

    public function test_access_requires_view_permission(): void
    {
        $this->actingAs($this->userWithPermissions());
        $this->get('/admin/data/projekte')->assertForbidden();

        $this->actingAs($this->userWithPermissions(['data.projekte.view']));
        $this->get('/admin/data/projekte')->assertOk()->assertSee('Projekte');
        $this->get('/admin/data/kunden')->assertForbidden();
    }

    public function test_unknown_or_inactive_tables_are_not_found(): void
    {
        $this->actingAs($this->superAdmin());
        $this->get('/admin/data/gibtsnicht')->assertNotFound();

        $this->service()->updateTable($this->projekte, ['is_active' => false]);
        $this->get('/admin/data/projekte')->assertNotFound();
    }

    public function test_system_tables_are_not_reachable_through_the_page(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/admin/data/users')->assertNotFound();
        $this->get('/admin/data/public.users')->assertNotFound();
    }

    public function test_viewer_cannot_create_edit_or_delete(): void
    {
        $record = DynamicRecord::forTable($this->projekte)->create(['titel' => 'Alpha']);
        $this->actingAs($this->userWithPermissions(['data.projekte.view']));

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertActionHidden(TestAction::make(CreateAction::class)->table())
            ->assertActionVisible(TestAction::make('details')->table($record))
            ->assertActionHidden([TestAction::make('details')->table($record), TestAction::make('edit')])
            ->assertActionHidden([TestAction::make('details')->table($record), TestAction::make('delete')]);
    }

    public function test_revoked_permission_takes_effect_on_next_interaction(): void
    {
        $user = $this->userWithPermissions(['data.projekte.view']);
        $this->actingAs($user);
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'projekte']);

        $user->revokePermissionTo('data.projekte.view');

        $page->call('$refresh')->assertForbidden();
    }

    // --- Anzeige -----------------------------------------------------------------------

    public function test_lists_records_with_relation_and_select_labels(): void
    {
        $kunde = DynamicRecord::forTable($this->kunden)->create(['name' => 'ACME GmbH']);
        $record = DynamicRecord::forTable($this->projekte)->create(['titel' => 'Alpha', 'status' => 'erledigt', 'kunde_id' => $kunde->id, 'intern' => 'geheim']);
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertCanSeeTableRecords([$record])
            ->assertSee('ACME GmbH')
            ->assertSee('Erledigt')
            ->assertDontSee('geheim');
    }

    public function test_search_and_filter(): void
    {
        $model = DynamicRecord::forTable($this->projekte);
        $alpha = $model->create(['titel' => 'Alpha', 'status' => 'offen']);
        $beta = $model->create(['titel' => 'Beta', 'status' => 'erledigt']);
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->searchTable('alp')
            ->assertCanSeeTableRecords([$alpha])
            ->assertCanNotSeeTableRecords([$beta]);

        // Die Suche bleibt in der Sitzung erhalten …
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSet('tableSearch', 'alp')
            ->assertCanNotSeeTableRecords([$beta]);

        // … und seit B-027 auch in einer neuen Sitzung (z. B. nach dem Abmelden).
        $this->flushSession();
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSet('tableSearch', 'alp')
            ->searchTable(null)
            ->filterTable('status', ['erledigt'])
            ->assertCanSeeTableRecords([$beta])
            ->assertCanNotSeeTableRecords([$alpha]);
    }

    // --- Bearbeiten und Validierung ----------------------------------------------------

    public function test_create_record_with_validation_and_audit(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);
        $kunde = DynamicRecord::forTable($this->kunden)->create(['name' => 'ACME']);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction(TestAction::make(CreateAction::class)->table(), ['titel' => 'Neu', 'status' => 'offen', 'budget' => '1500.50', 'kunde_id' => $kunde->id])
            ->assertHasNoFormErrors();

        $record = DynamicRecord::forTableName('projekte')->newQuery()->where('titel', 'Neu')->firstOrFail();
        $this->assertSame('1500.50', $record->budget);
        $this->assertSame($user->id, $record->created_by);
        $this->assertSame($user->id, Activity::query()->where('subject_type', 'dynamic:projekte')->where('subject_id', $record->id)->value('causer_id'));
    }

    public function test_validation_rules_from_metadata(): void
    {
        $this->actingAs($this->superAdmin());
        DynamicRecord::forTable($this->projekte)->create(['titel' => 'Bestehend', 'nummer' => 'P-1']);
        $geloeschterKunde = DynamicRecord::forTable($this->kunden)->create(['name' => 'Weg']);
        $geloeschterKunde->delete();

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'titel' => str_repeat('x', 21),   // > Länge 20
                'nummer' => 'P-1',                // eindeutig
                'status' => 'manipuliert',        // nicht in Auswahlliste
                'budget' => '-1',                 // min 0
                'kunde_id' => $geloeschterKunde->id, // gelöschter Datensatz
            ])
            ->assertHasFormErrors(['titel', 'nummer', 'status', 'budget', 'kunde_id']);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction(TestAction::make(CreateAction::class)->table(), ['titel' => ''])
            ->assertHasFormErrors(['titel' => 'required']);
    }

    public function test_hidden_and_system_fields_cannot_be_written(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction(TestAction::make(CreateAction::class)->table(), ['titel' => 'Neu', 'intern' => 'x', 'created_by' => 999])
            ->assertHasNoFormErrors();

        $record = DynamicRecord::forTableName('projekte')->newQuery()->where('titel', 'Neu')->firstOrFail();
        $this->assertNull($record->intern);
        $this->assertNotSame(999, $record->created_by);
    }

    public function test_edit_delete_restore_and_history_in_details_popup(): void
    {
        $this->actingAs($this->superAdmin());
        $record = DynamicRecord::forTable($this->projekte)->create(['titel' => 'Alt']);
        $details = fn () => TestAction::make('details')->table($record);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction([$details(), TestAction::make('edit')], ['titel' => 'Neu'])
            ->assertHasNoFormErrors()
            ->mountAction([$details(), TestAction::make('history')])
            ->assertMountedActionModalSee('titel: Alt → Neu');

        // Aktiver Datensatz: Löschen ja, Wiederherstellen nicht.
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertActionVisible([$details(), TestAction::make('delete')])
            ->assertActionHidden([$details(), TestAction::make('restore')]);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction([$details(), TestAction::make('delete')]);
        $this->assertSoftDeleted(DynamicRecord::forTable($this->projekte)->newQuery()->withTrashed()->find($record->id));

        // Gelöschter Datensatz: Wiederherstellen ja, Löschen nicht.
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->filterTable('trashed', false)
            ->assertActionHidden([TestAction::make('details')->table($record), TestAction::make('delete')])
            ->callAction([TestAction::make('details')->table($record), TestAction::make('restore')]);
        $this->assertNotNull(DynamicRecord::forTableName('projekte')->newQuery()->find($record->id));
    }

    // --- Menü und Protokoll ------------------------------------------------------------

    public function test_menu_entry_follows_table_and_respects_permissions(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateCustomTable::class)
            ->fillForm(['label' => ['de' => 'Verträge'], 'name' => 'vertraege',  'fields' => [['label' => ['de' => 'Titel'], 'name' => 'titel', 'type' => 'string']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = Menu::query()->where('route_parameters->table', 'vertraege')->firstOrFail();
        $this->assertSame(DataMenu::ROUTE, $entry->route);
        $this->assertSame('data.vertraege.view', $entry->permission_name);

        $viewer = User::factory()->create()->givePermissionTo('data.vertraege.view');
        $other = User::factory()->create();
        $labels = fn (User $u): array => collect(app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $u)->getNavigation())
            ->firstWhere(fn ($g) => $g->getLabel() === 'Daten')?->getItems() ?? [];

        $item = collect($labels($viewer))->first();
        $this->assertSame('Verträge', $item->getLabel());
        $this->assertSame(url('/admin/data/vertraege'), $item->getUrl());
        $this->assertSame([], $labels($other));

        $table = CustomTable::where('name', 'vertraege')->firstOrFail();
        $this->service()->updateTable($table, ['label' => 'Verträge 2026', 'is_active' => false]);
        $this->assertFalse($entry->fresh()->is_active);
        $this->assertSame('Verträge 2026', $entry->fresh()->label);

        $this->service()->dropTable($table->fresh(), 'vertraege');
        $this->assertModelMissing($entry);
    }

    public function test_activity_log_shows_table_label_and_links_to_data(): void
    {
        $this->actingAs($this->superAdmin());
        DynamicRecord::forTable($this->projekte)->create(['titel' => 'Alpha']);
        $activity = Activity::query()->where('subject_type', 'dynamic:projekte')->firstOrFail();

        $this->assertStringStartsWith('Daten: Projekte #', ActivityChanges::subjectLabel($activity));
        // Liste mit vorausgewähltem Datensatz (Master-Detail-Kaskade).
        $this->assertSame(url("/admin/data/projekte?sel={$activity->subject_id}"), RecordLink::forActivity($activity));
        $this->get('/admin/activity-log/'.$activity->id)->assertOk()->assertSee('Daten: Projekte');
    }
}
