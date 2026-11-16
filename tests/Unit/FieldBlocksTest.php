<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Support\FieldBlocks;
use PHPUnit\Framework\TestCase;

class FieldBlocksTest extends TestCase
{
    public function test_block_count_and_column_major_filling(): void
    {
        $fields = fn (int $n): array => array_map(fn (int $i): array => ['label' => "F{$i}", 'value' => (string) $i], range(1, $n));
        $shape = fn (int $n): array => array_map('count', FieldBlocks::blocks($fields($n)));

        $this->assertSame([], FieldBlocks::blocks([]));
        $this->assertSame([1], $shape(1));
        $this->assertSame([4], $shape(4), 'Bis 4 Felder: ein Block.');
        $this->assertSame([3, 2], $shape(5), 'Bis 8: zwei Blöcke, der letzte darf kürzer sein.');
        $this->assertSame([4, 4], $shape(8));
        $this->assertSame([3, 3, 3], $shape(9), 'Ab 9: drei Blöcke.');
        $this->assertSame([4, 4, 2], $shape(10));
        $this->assertSame([7, 7, 6], $shape(20));

        // Slide-ins: höchstens ein Block, also eine einzige Liste.
        $this->assertSame([10], array_map('count', FieldBlocks::blocks($fields(10), maxBlocks: 1)));
        $this->assertSame([5, 5], array_map('count', FieldBlocks::blocks($fields(10), maxBlocks: 2)));

        // Spaltenweise: der erste Block enthält die ersten Felder.
        $this->assertSame(['F1', 'F2', 'F3', 'F4'], array_column(FieldBlocks::blocks($fields(10))[0], 'label'));
    }
}
