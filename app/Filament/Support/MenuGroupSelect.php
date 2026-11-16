<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DynamicData\DataMenu;
use Filament\Forms\Components\Select;

/**
 * Auswahl der Menügruppe für den Menüeintrag einer Datentabelle (Table-Designer, Table-Connector).
 * Gruppen in Menüreihenfolge, Untergruppen eingerückt; leer = kein Menüeintrag.
 */
final class MenuGroupSelect
{
    public static function make(string $name = 'menu_group', bool $required = false): Select
    {
        return Select::make($name)
            ->label(__('menus.form.menu_group'))
            ->helperText(__('menus.form.menu_group_help'))
            ->options(fn (): array => app(DataMenu::class)->groupOptions())
            ->default(fn (): string => app(DataMenu::class)->defaultGroup())
            ->placeholder(__('menus.form.no_menu_entry'))
            ->selectablePlaceholder(! $required)
            ->required($required)
            // Natives Auswahlfeld: zeigt die Einrückung der Untergruppen zuverlässig.
            ->native();
    }
}
