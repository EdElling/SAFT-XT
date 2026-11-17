<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\TableConnector\Pages\CreateConnectedTable;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Menüeintrag einer Datentabelle unter einer wählbaren Gruppe oder Untergruppe. */
class MenuGroupSelectionTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    /** Auch app_data in der Test-Transaktion (PostgreSQL: DDL wird mit zurückgerollt). */
    protected array $connectionsToTransact = [null, 'app_data'];

    private const string INDENT = "\u{00A0}\u{00A0}\u{00A0}\u{00A0}";

    private Menu $data;

    private Menu $research;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();

        $this->data = Menu::create(['label' => ['de' => 'Daten'], 'sort_order' => 500, 'is_active' => true]);
        $this->research = Menu::create(['label' => ['de' => 'Forschung'], 'parent_id' => $this->data->id, 'sort_order' => 10, 'is_active' => true]);
    }

    private function table(string $name = 'kunden'): CustomTable
    {
        return app(DynamicSchemaService::class)->createTable(['name' => $name, 'label' => ucfirst($name)], [['name' => 'firma', 'type' => 'string']]);
    }

    public function test_group_options_show_the_tree_up_to_the_allowed_depth(): void
    {
        $third = Menu::create(['label' => ['de' => 'Zu tief'], 'parent_id' => $this->research->id, 'sort_order' => 10, 'is_active' => true]);
        Menu::create(['label' => ['de' => 'Kennzahlen'], 'sort_order' => 600, 'is_active' => true]);

        $options = app(DataMenu::class)->groupOptions();

        $this->assertSame([
            (string) $this->data->id => 'Daten',
            (string) $this->research->id => self::INDENT.'Daten › Forschung',
            (string) Menu::query()->where('label->de', 'Kennzahlen')->value('id') => 'Kennzahlen',
            (string) Menu::query()->where('label->de', 'Administration')->value('id') => 'Administration',
        ] + collect(['Benutzer und Zugriff', 'Datenbank', 'Anwendung', 'Betrieb'])->mapWithKeys(static fn (string $label): array => [
            // Vereinigung statt Spread: Spread nummeriert die numerischen Schlüssel (Menü-IDs) neu.
            (string) Menu::query()->where('label->de', $label)->value('id') => self::INDENT.'Administration › '.$label,
        ])->all(), $options);
        $this->assertArrayNotHasKey((string) $third->id, $options);
        // Einträge mit Seite (z. B. Dashboard) sind keine Gruppen.
        $this->assertNotContains('Dashboard', $options);
        $this->assertSame((string) $this->data->id, app(DataMenu::class)->defaultGroup());
    }

    public function test_missing_data_group_is_offered_and_created_on_demand(): void
    {
        $this->research->delete();
        $this->data->delete();

        $other = Menu::create(['label' => ['de' => 'Kennzahlen'], 'sort_order' => 600, 'is_active' => true]);
        $options = app(DataMenu::class)->groupOptions();
        $this->assertSame('Daten (wird angelegt)', $options[DataMenu::NEW_DATA_GROUP]);
        // Die übrigen Gruppen behalten ihre IDs als Schlüssel.
        $this->assertSame('Kennzahlen', $options[(string) $other->id]);
        $this->assertSame(DataMenu::NEW_DATA_GROUP, app(DataMenu::class)->defaultGroup());

        $entry = app(DataMenu::class)->create($this->table(), DataMenu::NEW_DATA_GROUP);
        $this->assertSame('Daten', $entry->parent->getTranslation('label', 'de'));
    }

    public function test_entries_can_be_created_under_subgroups_and_twice(): void
    {
        $table = $this->table();
        $menu = app(DataMenu::class);

        $first = $menu->create($table, (string) $this->research->id);
        $second = $menu->create($table, (string) $this->data->id);

        $this->assertSame($this->research->id, $first->parent_id);
        $this->assertSame($this->data->id, $second->parent_id);
        // ensure() legt keinen dritten an.
        $this->assertSame($first->id, $menu->ensure($table)->id);

        foreach ([(string) Menu::query()->where('route', 'filament.admin.pages.dashboard')->value('id'), '999999'] as $invalid) {
            try {
                $menu->create($table, $invalid);
                $this->fail("Gruppe {$invalid} akzeptiert.");
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_table_designer_create_and_edit_use_the_chosen_group(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateCustomTable::class)
            ->assertFormSet(['menu_group' => (string) $this->data->id])
            ->fillForm(['label' => ['de' => 'Projekte'], 'name' => 'projekte', 'menu_group' => (string) $this->research->id, 'fields' => [['label' => ['de' => 'Titel'], 'name' => 'titel', 'type' => 'string']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $projects = CustomTable::query()->where('name', 'projekte')->sole();
        $this->assertSame([$this->research->id], $this->parents('projekte'));

        Livewire::test(EditCustomTable::class, ['record' => $projects->getRouteKey()])
            ->callAction('createMenuEntry', ['menu_group' => (string) $this->data->id])
            ->assertHasNoActionErrors();
        $this->assertEqualsCanonicalizing([$this->research->id, $this->data->id], $this->parents('projekte'));

        // Ohne Gruppe kein Menüeintrag.
        Livewire::test(CreateCustomTable::class)
            ->fillForm(['label' => ['de' => 'Intern'], 'name' => 'intern', 'menu_group' => null, 'fields' => [['label' => ['de' => 'Titel'], 'name' => 'titel', 'type' => 'string']]])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame([], $this->parents('intern'));
    }

    public function test_table_connector_uses_the_chosen_group(): void
    {
        $this->prepareExternalDatabase();
        $connection = $this->researchConnection();
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateConnectedTable::class)
            ->fillForm(['database_connection_id' => $connection->id])
            ->fillForm(['external_table' => 'projekte'])
            ->assertFormSet(['menu_group' => (string) $this->data->id])
            ->fillForm(['menu_group' => (string) $this->research->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->research->id], $this->parents('forschung_projekte'));
    }

    /** @return list<int> Gruppen der Menüeinträge einer Tabelle */
    private function parents(string $table): array
    {
        return Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', $table)->pluck('parent_id')->map(fn ($id): int => (int) $id)->all();
    }
}
