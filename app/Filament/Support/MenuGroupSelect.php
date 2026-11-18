<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DynamicData\DataMenu;
use Closure;
use Filament\Forms\Components\Select;

/**
 * Auswahl der Menügruppe für den Menüeintrag einer Datentabelle (Table-Designer, Table-Connector).
 * Gruppen in Menüreihenfolge, Untergruppen eingerückt; leer = kein Menüeintrag.
 */
final class MenuGroupSelect
{
    /**
     * @param  Closure|null  $app  liefert die App (ID) der Tabelle, z. B. fn (Get $get) => $get('app_id'); Gruppen nur dieser App
     */
    public static function make(string $name = 'menu_group', bool $required = false, ?Closure $app = null): Select
    {
        $appId = static fn (Select $component): ?int => $app === null ? null : (filled($id = $component->evaluate($app)) ? (int) $id : null);

        return Select::make($name)
            ->label(__('menus.form.menu_group'))
            ->helperText(__('menus.form.menu_group_help'))
            ->options(fn (Select $component): array => app(DataMenu::class)->groupOptions($appId($component)))
            ->default(fn (Select $component): string => app(DataMenu::class)->defaultGroup($appId($component)))
            ->placeholder(__('menus.form.no_menu_entry'))
            ->selectablePlaceholder(! $required)
            ->required($required)
            // Natives Auswahlfeld: zeigt die Einrückung der Untergruppen zuverlässig.
            ->native();
    }
}
