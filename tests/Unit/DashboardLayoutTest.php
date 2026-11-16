<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Dashboard\DashboardLayout as L;
use PHPUnit\Framework\TestCase;

class DashboardLayoutTest extends TestCase
{
    /** @return array<string, array{span: string, default: string}> */
    private function cards(): array
    {
        return [
            'a' => ['span' => L::SPAN_HALF, 'default' => 'half'],
            'b' => ['span' => L::SPAN_HALF, 'default' => 'half'],
            'c' => ['span' => L::SPAN_HALF, 'default' => 'half'],
            'wide' => ['span' => L::SPAN_FULL, 'default' => 'full'],
            'report:1' => ['span' => L::SPAN_FLEX, 'default' => 'half'],
            'report:2' => ['span' => L::SPAN_FLEX, 'default' => 'full'],
        ];
    }

    public function test_default_alternates_half_cards_and_puts_full_ones_below(): void
    {
        $this->assertSame(
            ['left' => ['a', 'c'], 'right' => ['b', 'report:1'], 'full' => ['wide', 'report:2'], 'hidden' => []],
            L::resolve($this->cards(), null),
        );
    }

    public function test_saved_order_is_kept_and_invalid_entries_dropped(): void
    {
        $saved = [
            'left' => ['report:2', 'b', 'gone', 'b'],
            'right' => ['wide', 'a'],           // volle Breite darf nicht in eine Spalte
            'full' => ['report:1'],             // Auswertung breit gemacht
            'hidden' => ['c'],
        ];

        $this->assertSame(
            ['left' => ['report:2', 'b'], 'right' => ['a'], 'full' => ['report:1', 'wide'], 'hidden' => ['c']],
            L::resolve($this->cards(), $saved),
            'Unbekanntes und Doppeltes fällt weg; „wide“ kommt an seinen Standardplatz.',
        );
    }

    public function test_new_cards_go_to_the_shorter_column(): void
    {
        $layout = L::resolve($this->cards(), ['left' => ['a', 'b'], 'right' => [], 'full' => ['wide', 'report:2', 'report:1'], 'hidden' => []]);

        $this->assertSame(['c'], $layout['right']);
    }
}
