<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Menu;
use App\Navigation\MenuTree;
use Tests\TestCase;

class MenuTreeTest extends TestCase
{
    private MenuTree $tree;

    protected function setUp(): void
    {
        parent::setUp();

        // 1 Root → 2 → 3 ; 4 Root (Blatt)
        $this->tree = new MenuTree(collect([
            $this->menu(1, null, 'Root'),
            $this->menu(2, 1, 'Mitte'),
            $this->menu(3, 2, 'Blatt'),
            $this->menu(4, null, 'Solo'),
        ]));
    }

    public function test_path_and_depth(): void
    {
        $this->assertSame(['Root', 'Mitte', 'Blatt'], $this->tree->path(3));
        $this->assertSame(3, $this->tree->depth(3));
        $this->assertSame(3, $this->tree->height(1));
    }

    public function test_prevents_cycles(): void
    {
        $this->assertFalse($this->tree->canAttach(1, 3));
        $this->assertFalse($this->tree->canAttach(1, 1));
    }

    public function test_enforces_max_depth_including_subtree(): void
    {
        $this->assertFalse($this->tree->canAttach(null, 3));   // neue 4. Ebene
        $this->assertTrue($this->tree->canAttach(null, 2));    // neue 3. Ebene
        $this->assertTrue($this->tree->canAttach(2, 4));       // Teilbaum Höhe 2 unter Ebene 1 → 3 Ebenen
        $this->assertFalse($this->tree->canAttach(1, 4));      // Teilbaum Höhe 3 unter Ebene 1 → 4 Ebenen
    }

    public function test_parent_options_exclude_invalid_targets(): void
    {
        $options = $this->tree->parentOptions(1);

        $this->assertArrayNotHasKey(1, $options);
        $this->assertArrayNotHasKey(2, $options);
        $this->assertArrayNotHasKey(3, $options);
    }

    private function menu(int $id, ?int $parentId, string $label): Menu
    {
        $menu = new Menu;
        $menu->forceFill(['id' => $id, 'parent_id' => $parentId, 'label' => $label]);

        return $menu;
    }
}
