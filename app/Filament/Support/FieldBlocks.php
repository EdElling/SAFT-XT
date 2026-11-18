<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Schemas\Components\View;

/**
 * Felder kompakt anzeigen: je Feld Name und daneben der Wert, in bis zu drei Blöcken nebeneinander
 * (1 Block bis 4 Felder, 2 bis 8, sonst 3), spaltenweise gefüllt – jeder Block hat ⌈Felder/Blöcke⌉
 * Zeilen, der letzte darf kürzer sein. Oben und links ausgerichtet (Zahlen mit gleich breiten Ziffern),
 * lange Texte brechen um. Ist der Platz schmal (Telefon), stehen die Blöcke untereinander; Slide-ins
 * (hoch und schmal) nutzen höchstens einen Block, also eine einzige Liste.
 */
final class FieldBlocks
{
    public const int MAX_BLOCKS = 3;

    /**
     * @param  list<array{label: string, value: ?string, numeric?: bool, html?: ?string}>  $fields
     */
    public static function make(array $fields, ?string $empty = null, int $maxBlocks = self::MAX_BLOCKS): View
    {
        return View::make('filament.components.field-blocks')
            ->viewData(['blocks' => self::blocks($fields, $maxBlocks), 'empty' => $empty ?? '—']);
    }

    /**
     * @param  list<array{label: string, value: ?string, numeric?: bool, html?: ?string}>  $fields
     * @return list<list<array{label: string, value: ?string, numeric?: bool, html?: ?string}>>
     */
    public static function blocks(array $fields, int $maxBlocks = self::MAX_BLOCKS): array
    {
        $count = count($fields);

        if ($count === 0) {
            return [];
        }

        $blocks = min(max(1, $maxBlocks), match (true) {
            $count <= 4 => 1,
            $count <= 8 => 2,
            default => self::MAX_BLOCKS,
        });

        return array_chunk(array_values($fields), (int) ceil($count / $blocks));
    }
}
