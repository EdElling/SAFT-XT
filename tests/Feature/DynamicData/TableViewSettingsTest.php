<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\User;
use App\Models\UserTableColumn;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Spaltenauswahl (dauerhaft je Benutzer), Filter/Suche/Sortierung je Sitzung, Zahlen rechtsbündig,
 * Formularbreite und Dashboard-Spalten.
 */
class TableViewSettingsTest extends DynamicDataTestCase
{
    private CustomTable $projekte;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projekte = $this->service()->createTable(['name' => 'projekte', 'label' => 'Projekte'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'budget', 'label' => 'Budget', 'type' => 'decimal', 'options' => ['precision' => 8, 'scale' => 2]],
            ['name' => 'anzahl', 'label' => 'Anzahl', 'type' => 'integer'],
            ['name' => 'intern', 'label' => 'Intern', 'type' => 'string', 'show_in_table' => false],
        ]);
        DynamicRecord::forTable($this->projekte)->create(['titel' => 'Alpha', 'budget' => 1200.5, 'anzahl' => 3, 'intern' => 'geheim']);
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
    }

    /**
     * Spaltenauswahl wie im Spalten-Menü von Filament.
     *
     * @param  array<string, bool>  $toggles
     */
    private function toggle(Testable $component, array $toggles): Testable
    {
        $state = array_map(static function (array $item) use ($toggles): array {
            if (array_key_exists($item['name'], $toggles)) {
                $item['isToggled'] = $toggles[$item['name']];
            }

            return $item;
        }, $component->instance()->getDefaultTableColumnState());

        return $component->call('applyTableColumnManager', $state);
    }

    /**
     * Sichtbarkeit laut Spaltenauswahl (assertTableColumnHidden prüft nur feste Sichtbarkeit).
     *
     * @param  list<string>  $shown
     * @param  list<string>  $hidden
     */
    private function assertColumns(Testable $component, array $shown = [], array $hidden = []): void
    {
        foreach ($shown as $name) {
            $this->assertFalse($component->instance()->isTableColumnToggledHidden($name), "Spalte {$name} sollte sichtbar sein.");
        }

        foreach ($hidden as $name) {
            $this->assertTrue($component->instance()->isTableColumnToggledHidden($name), "Spalte {$name} sollte ausgeblendet sein.");
        }
    }

    public function test_hidden_fields_can_be_shown_and_the_choice_is_stored_per_user(): void
    {
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertTableColumnExists('intern')
            ->assertDontSee('geheim');
        $this->assertColumns($page, shown: ['titel', 'budget'], hidden: ['intern']);

        $this->toggle($page, ['intern' => true, 'budget' => false]);
        $this->assertColumns($page, shown: ['intern'], hidden: ['budget']);

        $stored = UserTableColumn::query()->where('user_id', $this->admin->id)->sole();
        $this->assertSame(['budget' => false, 'intern' => true], UserTableColumn::columnsFor($this->admin->id, $stored->table_key));

        // Neue Sitzung (anderer Browser): die Auswahl bleibt.
        $this->flushSession();
        $again = Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])->assertSee('geheim');
        $this->assertColumns($again, shown: ['intern'], hidden: ['budget']);

        // Andere Benutzer sehen die Vorgabe.
        $this->actingAs($this->superAdmin());
        $this->assertColumns(Livewire::test(DynamicDataPage::class, ['table' => 'projekte']), shown: ['budget'], hidden: ['intern']);
    }

    public function test_required_fields_cannot_be_hidden_and_reset_restores_the_default(): void
    {
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'projekte']);
        $titel = collect($page->instance()->getDefaultTableColumnState())->firstWhere('name', 'titel');
        $this->assertFalse($titel['isToggleable'], 'Pflichtfeld bleibt immer sichtbar.');

        $this->toggle($page, ['intern' => true]);
        $this->assertSame(1, UserTableColumn::query()->count());

        $page->call('resetTableColumnManager');
        $this->assertColumns($page, hidden: ['intern']);
        $this->assertSame(0, UserTableColumn::query()->count(), 'Vorgabe gilt wieder: kein Eintrag.');
    }

    public function test_menu_views_keep_their_own_choice_and_deleted_fields_are_ignored(): void
    {
        $menu = Menu::create([
            'label' => 'Budgets',
            'route' => DataMenu::ROUTE,
            'route_parameters' => ['table' => 'projekte'],
            'view_config' => ['columns' => [$this->fieldId('projekte', 'titel'), $this->fieldId('projekte', 'budget')]],
            'is_active' => true,
        ]);

        $this->toggle(Livewire::test(DynamicDataPage::class, ['table' => 'projekte']), ['intern' => true]);

        $view = Livewire::withQueryParams(['view' => $menu->id])->test(DynamicDataPage::class, ['table' => 'projekte']);
        $this->assertColumns($view, shown: ['budget'], hidden: ['intern', 'anzahl']);

        // Gespeicherte Spalte eines gelöschten Felds stört nicht.
        $intern = $this->projekte->fields()->where('name', 'intern')->firstOrFail();
        $this->service()->updateField($intern, ['is_active' => false]);
        $this->service()->dropField($intern->fresh(), 'intern');

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])->assertTableColumnDoesNotExist('intern');
        $this->assertColumns($page, shown: ['budget']);
    }

    public function test_admin_lists_remember_columns_too(): void
    {
        $this->toggle(Livewire::test(ListUsers::class), ['created_at' => true]);
        $this->flushSession();

        $this->assertColumns(Livewire::test(ListUsers::class), shown: ['created_at']);
    }

    public function test_export_follows_the_visible_columns(): void
    {
        $page = $this->toggle(Livewire::test(DynamicDataPage::class, ['table' => 'projekte']), ['intern' => true, 'budget' => false]);
        $this->service()->updateTable($this->projekte, ['export_enabled' => true]);

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'projekte']);
        $page->callTableAction('export_csv');
        $csv = (string) $page->effects['download']['content'] ?? '';
        $content = base64_decode($csv, true) ?: $csv;

        $this->assertStringContainsString('Intern', $content);
        $this->assertStringNotContainsString('Budget', $content);
    }

    public function test_filters_search_and_sort_survive_leaving_the_list(): void
    {
        Livewire::test(ListMenus::class)
            ->searchTable('Benutzer')
            ->sortTable('sort_order', 'desc');

        $menu = Menu::query()->firstOrFail();
        Livewire::test(EditMenu::class, ['record' => $menu->getKey()]);

        Livewire::test(ListMenus::class)
            ->assertSet('tableSearch', 'Benutzer')
            ->assertSet('tableSort', 'sort_order:desc');

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])->searchTable('Alpha');
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])->assertSet('tableSearch', 'Alpha');
    }

    public function test_ten_rows_by_default_and_another_choice_is_remembered(): void
    {
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSet('tableRecordsPerPage', 10)
            ->set('tableRecordsPerPage', 50);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])->assertSet('tableRecordsPerPage', 50);

        // Seit B-027 auch in einer neuen Sitzung (z. B. nach dem Abmelden); „Ansicht zurücksetzen“ führt zu 10.
        $this->flushSession();
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertSet('tableRecordsPerPage', 50)
            ->callTableAction('resetTableView')
            ->assertSet('tableRecordsPerPage', 10);
    }

    public function test_numbers_are_right_aligned(): void
    {
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertTableColumnExists('budget', fn (TextColumn $column): bool => $column->getAlignment() === Alignment::End)
            ->assertTableColumnExists('anzahl', fn (TextColumn $column): bool => $column->getAlignment() === Alignment::End)
            ->assertTableColumnExists('titel', fn (TextColumn $column): bool => $column->getAlignment() !== Alignment::End);

        Livewire::test(ListMenus::class)
            ->assertTableColumnExists('sort_order', fn (TextColumn $column): bool => $column->getAlignment() === Alignment::End);
    }

    public function test_forms_are_limited_and_lists_use_the_full_width(): void
    {
        $this->assertSame(Width::SevenExtraLarge, Livewire::test(EditMenu::class, ['record' => Menu::query()->firstOrFail()->getKey()])->instance()->getMaxContentWidth());
        $this->assertNotSame(Width::SevenExtraLarge, Livewire::test(ListMenus::class)->instance()->getMaxContentWidth());
    }

    public function test_dashboard_stacks_half_width_widgets_in_two_columns(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSeeHtml('data-dashboard-column="left"')
            ->assertSeeHtml('data-dashboard-column="right"')
            ->assertSeeHtml('data-dashboard-column="full"');
    }
}
