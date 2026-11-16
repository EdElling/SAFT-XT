<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\Pages\ImportCustomTable;
use App\Filament\Resources\CustomTables\Pages\ListCustomTables;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\CustomTables\RelationManagers\UsagesRelationManager;
use App\Filament\Resources\CustomTables\Schemas\CustomTableForm;
use App\Filament\Resources\CustomTables\Tables\CustomTablesTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\CustomTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomTableResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = CustomTable::class;

    protected static ?string $slug = 'table-designer';

    protected static ?string $recordTitleAttribute = 'label';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('tables.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tables.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomTableForm::configure($schema);
    }

    /** Nur Designer-Tabellen; angebundene externe Tabellen pflegt der Table-Connector. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->designer();
    }

    public static function table(Table $table): Table
    {
        return CustomTablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CustomFieldsRelationManager::class,
            UsagesRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomTables::route('/'),
            'create' => CreateCustomTable::route('/create'),
            'import' => ImportCustomTable::route('/import'),
            'edit' => EditCustomTable::route('/{record}/edit'),
        ];
    }
}
