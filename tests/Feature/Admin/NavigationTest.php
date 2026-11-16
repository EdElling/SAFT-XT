<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Menu;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    public function test_user_only_sees_permitted_entries(): void
    {
        $nav = $this->labels($this->userWithPermissions(['users.view']));

        $this->assertSame(['Dashboard', 'Dokumentation'], $nav['items']);
        $this->assertSame(['Administration' => ['Benutzer']], $nav['groups']);
    }

    public function test_super_admin_sees_everything(): void
    {
        $nav = $this->labels($this->superAdmin());

        $this->assertSame(
            ['Administration' => ['Benutzer', 'Rollen', 'Menüs', 'Änderungsprotokoll', 'Authentifizierung', 'Identity Provider', 'Table-Designer', 'Table-Connector', 'Tabellenverknüpfungen', 'Datenbankverbindungen', 'Geplante Aufgaben', 'Plugins', 'App-Einstellungen', 'Konfiguration übertragen']],
            $nav['groups'],
        );
    }

    public function test_group_without_visible_children_is_hidden(): void
    {
        $nav = $this->labels($this->userWithPermissions());

        $this->assertSame(['Dashboard', 'Dokumentation'], $nav['items']);
        $this->assertSame([], $nav['groups']);
    }

    public function test_entry_with_unknown_route_is_hidden_without_error(): void
    {
        $admin = Menu::where('label->de', 'Administration')->firstOrFail();
        Menu::create(['parent_id' => $admin->id, 'label' => 'Kaputt', 'route' => 'does.not.exist', 'sort_order' => 1]);

        $nav = $this->labels($this->superAdmin());

        $this->assertNotContains('Kaputt', $nav['groups']['Administration']);
    }

    public function test_inactive_entries_are_hidden(): void
    {
        Menu::where('label->de', 'Rollen')->update(['is_active' => false]);

        $nav = $this->labels($this->superAdmin());

        $this->assertNotContains('Rollen', $nav['groups']['Administration']);
    }

    public function test_third_level_becomes_child_item(): void
    {
        $users = Menu::where('label->de', 'Benutzer')->firstOrFail();
        Menu::create(['parent_id' => $users->id, 'label' => 'Rollen-Zuordnung', 'route' => 'filament.admin.resources.roles.index']);

        $builder = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $this->superAdmin());
        $group = collect($builder->getNavigation())->first(fn (NavigationGroup $g): bool => $g->getLabel() === 'Administration');
        $item = collect($group->getItems())->first(fn (NavigationItem $i): bool => $i->getLabel() === 'Benutzer');

        $this->assertSame(['Rollen-Zuordnung'], collect($item->getChildItems())->map->getLabel()->all());
    }

    public function test_panel_renders_dynamic_navigation(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view']))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Benutzer')
            ->assertDontSee('Änderungsprotokoll');
    }

    /**
     * @return array{items: list<string>, groups: array<string, list<string>>}
     */
    private function labels(User $user): array
    {
        $builder = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $user);
        $items = [];
        $groups = [];

        foreach ($builder->getNavigation() as $group) {
            $labels = collect($group->getItems())->map(fn (NavigationItem $i): string => (string) $i->getLabel())->values()->all();

            if ($group->getLabel() === null) {
                $items = [...$items, ...$labels];
            } else {
                $groups[(string) $group->getLabel()] = $labels;
            }
        }

        return ['items' => $items, 'groups' => $groups];
    }
}
