<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\DynamicData\DataMenu;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Navigation\MenuNavigationBuilder;
use App\Navigation\MenuTree;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class MenuTranslationTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    /**
     * @return array{items: list<string>, groups: array<string, list<string>>}
     */
    private function navigation(): array
    {
        $builder = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $this->superAdmin());
        $result = ['items' => [], 'groups' => []];

        foreach ($builder->getNavigation() as $group) {
            /** @var NavigationGroup $group */
            $labels = collect($group->getItems())->map(fn (NavigationItem $i): string => trim(strip_tags((string) $i->getLabel())))->values()->all();

            if ($group->getLabel() === null) {
                $result['items'] = [...$result['items'], ...$labels];
            } else {
                $result['groups'][(string) $group->getLabel()] = $labels;
            }
        }

        return $result;
    }

    public function test_seeded_menu_follows_the_language(): void
    {
        app()->setLocale('en');

        $this->assertSame(
            ['Administration' => ['Users and access', 'Database', 'Application', 'Operations']],
            $this->navigation()['groups'],
        );

        app()->setLocale('de');
        $this->assertContains('Benutzer und Zugriff', $this->navigation()['groups']['Administration']);

        app()->setLocale('fr');
        $this->assertSame(['Utilisateurs et accès', 'Base de données'], array_slice($this->navigation()['groups']['Administration'], 0, 2));
        $this->assertSame(['Tableau de bord', 'Documentation', 'Rapports'], $this->navigation()['items']);

        app()->setLocale('it');
        $this->assertArrayHasKey('Amministrazione', $this->navigation()['groups']);
    }

    public function test_missing_translation_falls_back_to_german(): void
    {
        $admin = Menu::where('label->de', 'Administration')->firstOrFail();
        Menu::create(['parent_id' => $admin->id, 'label' => ['de' => 'Nur Deutsch'], 'url' => 'https://example.com', 'sort_order' => 1]);

        app()->setLocale('en');

        $this->assertContains('Nur Deutsch', $this->navigation()['groups']['Administration']);
    }

    public function test_sorting_uses_the_label_of_the_current_language(): void
    {
        Menu::query()->delete();
        Menu::create(['label' => ['de' => 'Alpha', 'en' => 'Zulu'], 'url' => 'https://a.example', 'sort_order' => 1]);
        Menu::create(['label' => ['de' => 'Zulu', 'en' => 'Alpha'], 'url' => 'https://z.example', 'sort_order' => 1]);

        app()->setLocale('de');
        $this->assertSame(['Alpha', 'Zulu'], array_values(MenuTree::fromDatabase()->parentOptions()));

        app()->setLocale('en');
        $this->assertSame(['Alpha', 'Zulu'], array_values(MenuTree::fromDatabase()->parentOptions()));
        $this->assertSame('https://z.example', Menu::query()->orderedByLabel()->first()->url);
    }

    public function test_form_saves_and_shows_every_language(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateMenu::class)
            ->fillForm(['label.de' => 'Berichte', 'label.en' => 'Reports', 'type' => 'url', 'url' => 'https://example.com'])
            ->call('create')
            ->assertHasNoFormErrors();

        $menu = Menu::where('label->de', 'Berichte')->firstOrFail();
        $this->assertSame(['de' => 'Berichte', 'en' => 'Reports'], $menu->getTranslations('label'));

        Livewire::test(EditMenu::class, ['record' => $menu->getRouteKey()])
            ->assertFormSet(['label.de' => 'Berichte', 'label.en' => 'Reports'])
            ->fillForm(['label.en' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['de' => 'Berichte'], $menu->fresh()->getTranslations('label'), 'Leeres Englisch wird nicht gespeichert');
    }

    public function test_german_label_is_required(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateMenu::class)
            ->fillForm(['label.de' => '', 'label.en' => 'Reports', 'type' => 'url', 'url' => 'https://example.com'])
            ->call('create')
            ->assertHasFormErrors(['label.de' => 'required']);
    }

    public function test_list_search_and_global_search_find_every_language(): void
    {
        $this->actingAs($this->superAdmin());
        $admin = Menu::where('label->de', 'Administration')->firstOrFail();
        $users = Menu::where('label->de', 'Benutzer')->firstOrFail();

        Livewire::test(ListMenus::class)
            ->set('activeTab', 'all')
            ->searchTable('Activity')
            ->assertCanSeeTableRecords([Menu::where('label->de', 'Änderungsprotokoll')->firstOrFail()])
            ->assertCanNotSeeTableRecords([$users, $admin])
            ->searchTable('Benutz')
            ->assertCanSeeTableRecords([$users]);

        // Englischer Suchbegriff, deutsche Oberfläche: Treffer mit der deutschen Bezeichnung.
        $this->assertSame(['Benutzer und Zugriff', 'Benutzer'], MenuResource::getGlobalSearchResults('Users')->map(fn ($result): string => (string) $result->title)->values()->all());
    }

    public function test_data_group_is_created_in_both_languages_and_found_again(): void
    {
        $table = CustomTable::create(['name' => 'projekte', 'label' => 'Projekte']);
        $menu = app(DataMenu::class);

        $first = $menu->ensure($table);
        $group = $first->parent;
        $this->assertEqualsCanonicalizing(['de' => 'Daten', 'en' => 'Data', 'fr' => 'Données', 'it' => 'Dati'], $group->getTranslations('label'));

        $other = CustomTable::create(['name' => 'kunden', 'label' => 'Kunden']);
        $this->assertSame($group->id, $menu->ensure($other)->parent_id, 'Keine zweite Gruppe');
    }
}
