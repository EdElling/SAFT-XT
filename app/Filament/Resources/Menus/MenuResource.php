<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\Menus\Schemas\MenuForm;
use App\Filament\Resources\Menus\Tables\MenusTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\Menu;
use App\Support\AppLocale;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class MenuResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = Menu::class;

    protected static ?string $recordTitleAttribute = 'label';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Die Bezeichnung ist JSON je Sprache; die globale Suche fragt jede Sprache einzeln ab.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return array_map(static fn (string $locale): string => "label->{$locale}", AppLocale::available());
    }

    public static function getModelLabel(): string
    {
        return __('menus.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('menus.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return MenuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenusTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenus::route('/'),
            'create' => CreateMenu::route('/create'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
