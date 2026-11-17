<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Menu;
use App\Models\Report;
use App\Models\User;
use App\Navigation\MenuNavigationBuilder;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame(['Administration' => ['Benutzer und Zugriff']], $nav['groups']);
    }

    public function test_super_admin_sees_everything(): void
    {
        $nav = $this->labels($this->superAdmin());

        $this->assertSame(
            ['Administration' => ['Benutzer und Zugriff', 'Datenbank', 'Anwendung', 'Betrieb']],
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
        // Untermenüs der Administration (B-066): Einträge als Kind-Einträge, Klick öffnet die erste Seite.
        $builder = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $this->superAdmin());
        $group = collect($builder->getNavigation())->first(fn (NavigationGroup $g): bool => $g->getLabel() === 'Administration');
        $item = collect($group->getItems())->first(fn (NavigationItem $i): bool => trim(strip_tags((string) $i->getLabel())) === 'Benutzer und Zugriff');

        $this->assertSame(['Benutzer', 'Rollen', 'Authentifizierung', 'Identity Provider'], collect($item->getChildItems())->map->getLabel()->all());
        // Untermenü mit Pfeil, Seiten ohne: optisch unterscheidbar.
        $this->assertStringContainsString('saft-submenu-chevron', (string) $item->getLabel());
        $this->assertStringContainsString('<span class="saft-submenu-label">Benutzer und Zugriff</span>', (string) $item->getLabel());
        $this->assertSame('Benutzer', collect($item->getChildItems())->first()->getLabel(), 'Seiten ohne HTML.');
        $this->assertStringEndsWith('/admin/users', (string) $item->getUrl());

        // Eine vierte Ebene wird nicht angezeigt.
        $users = Menu::where('label->de', 'Benutzer')->firstOrFail();
        Menu::create(['parent_id' => $users->id, 'label' => 'Zu tief', 'route' => 'filament.admin.resources.roles.index']);
        $builder = app(MenuNavigationBuilder::class)->build(new NavigationBuilder, $this->superAdmin());
        $group = collect($builder->getNavigation())->first(fn (NavigationGroup $g): bool => $g->getLabel() === 'Administration');
        $item = collect($group->getItems())->first(fn (NavigationItem $i): bool => trim(strip_tags((string) $i->getLabel())) === 'Benutzer und Zugriff');
        $this->assertSame([], collect($item->getChildItems())->first(fn (NavigationItem $i): bool => $i->getLabel() === 'Benutzer')->getChildItems());
    }

    public function test_navigation_is_built_once_per_request(): void
    {
        // B-068: Filament ruft die Navigation mehrfach ab; jeder Aufbau kostete bei einer entfernten Datenbank Sekunden.
        $group = Menu::where('label->de', 'Administration')->firstOrFail();
        foreach (range(1, 5) as $n) {
            $report = Report::query()->create(['name' => "Auswertung {$n}", 'definition' => ['base' => 0, 'fields' => []], 'is_shared' => true]);
            Menu::create(['parent_id' => $group->id, 'label' => "Auswertung {$n}", 'route' => 'filament.admin.resources.reports.view', 'route_parameters' => ['record' => (string) $report->id], 'sort_order' => 900 + $n, 'is_active' => true]);
        }
        $this->actingAs($this->superAdmin());
        $this->get('/admin/users')->assertOk();

        DB::enableQueryLog();
        $this->get('/admin/users')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');

        $this->assertSame(1, count(array_filter($queries, static fn (string $q): bool => str_contains($q, 'from "menus" where') || str_contains($q, 'from `menus` where'))), 'Menüs einmal laden.');
        $this->assertLessThanOrEqual(1, count(array_filter($queries, static fn (string $q): bool => preg_match('/from [`"]reports[`"]/', $q) === 1)), 'Auswertungen der Einträge gesammelt.');
        $this->assertLessThan(60, count($queries), 'Seite ohne Hunderte von Abfragen.');
    }

    public function test_navigation_follows_menu_changes_within_the_same_request(): void
    {
        $this->actingAs($user = $this->superAdmin());
        $before = MenuNavigationBuilder::cachedForRequest(new NavigationBuilder, $user);
        Menu::create(['label' => 'Neu', 'route' => 'filament.admin.resources.users.index', 'sort_order' => 950, 'is_active' => true]);
        $after = MenuNavigationBuilder::cachedForRequest(new NavigationBuilder, $user);

        $this->assertNotSame($before, $after, 'Geänderte Menüs: neu aufgebaut.');
        $this->assertSame($after, MenuNavigationBuilder::cachedForRequest(new NavigationBuilder, $user), 'Unverändert: aus dem Zwischenspeicher.');
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
            $labels = collect($group->getItems())->map(fn (NavigationItem $i): string => trim(strip_tags((string) $i->getLabel())))->values()->all();

            if ($group->getLabel() === null) {
                $items = [...$items, ...$labels];
            } else {
                $groups[(string) $group->getLabel()] = $labels;
            }
        }

        return ['items' => $items, 'groups' => $groups];
    }
}
