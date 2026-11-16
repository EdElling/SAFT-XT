<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Models\Menu;
use App\Rules\KnownIcon;
use App\Support\IconCatalog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Auswahldialog für Icons im Menü-Formular und im Table-Designer. */
class IconPickerTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->actingAs($this->superAdmin());
    }

    public function test_catalog_groups_heroicons_by_style_and_hides_filament_icons(): void
    {
        $groups = collect(IconCatalog::groups())->keyBy('key');

        $this->assertSame(['outline', 'solid', 'mini', 'micro'], $groups->keys()->all());
        $this->assertContains('heroicon-o-users', $groups['outline']['icons']);
        $this->assertContains('heroicon-s-users', $groups['solid']['icons']);
        $this->assertSame('Outline', $groups['outline']['label']);
        $this->assertTrue(collect($groups->pluck('icons')->flatten())->every(fn (string $icon): bool => str_starts_with($icon, 'heroicon-')));
        $this->assertTrue(KnownIcon::exists($groups['micro']['icons'][0]));
        $this->assertSame('solid', IconCatalog::groupOf('heroicon-s-star'));
        $this->assertNull(IconCatalog::groupOf('gibt-es-nicht'));
    }

    public function test_dialog_shows_tabs_and_takes_over_the_clicked_icon(): void
    {
        Livewire::test(CreateMenu::class)
            ->mountAction(TestAction::make('pickIcon')->schemaComponent('icon'))
            ->assertMountedActionModalSee(['Outline', 'Solid', 'Mini', 'Micro'])
            ->assertMountedActionModalSeeHtml('data-icon="heroicon-o-academic-cap"')
            ->setActionData(['icon' => 'heroicon-s-star'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['icon' => 'heroicon-s-star']);
    }

    public function test_current_icon_is_highlighted_on_its_tab(): void
    {
        $menu = Menu::query()->whereNotNull('icon')->firstOrFail();
        $menu->update(['icon' => 'heroicon-m-bell']);

        Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
            ->mountAction(TestAction::make('pickIcon')->schemaComponent('icon'))
            ->assertMountedActionModalSeeHtml('class="saft-icon-picker-item is-current"')
            ->assertMountedActionModalSeeHtml('tab: \'mini\'');
    }

    public function test_unknown_icons_are_ignored_and_still_validated(): void
    {
        Livewire::test(CreateMenu::class)
            ->fillForm(['icon' => 'heroicon-o-users'])
            ->mountAction(TestAction::make('pickIcon')->schemaComponent('icon'))
            ->setActionData(['icon' => 'heroicon-o-gibt-es-nicht'])
            ->callMountedAction()
            ->assertSchemaStateSet(['icon' => 'heroicon-o-users']);

        Livewire::test(CreateCustomTable::class)
            ->fillForm(['name' => 'icons', 'icon' => 'heroicon-o-gibt-es-nicht'])
            ->call('create')
            ->assertHasFormErrors(['icon']);
    }

    public function test_table_designer_offers_the_dialog(): void
    {
        Livewire::test(CreateCustomTable::class)
            ->mountAction(TestAction::make('pickIcon')->schemaComponent('icon'))
            ->setActionData(['icon' => 'heroicon-o-table-cells'])
            ->callMountedAction()
            ->assertSchemaStateSet(['icon' => 'heroicon-o-table-cells']);
    }
}
