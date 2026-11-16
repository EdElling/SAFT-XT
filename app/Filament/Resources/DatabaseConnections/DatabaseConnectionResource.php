<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\DatabaseConnections\Pages\CreateDatabaseConnection;
use App\Filament\Resources\DatabaseConnections\Pages\EditDatabaseConnection;
use App\Filament\Resources\DatabaseConnections\Pages\ListDatabaseConnections;
use App\Filament\Resources\DatabaseConnections\Schemas\DatabaseConnectionForm;
use App\Filament\Resources\DatabaseConnections\Tables\DatabaseConnectionsTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\DatabaseConnection;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DatabaseConnectionResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = DatabaseConnection::class;

    protected static ?string $slug = 'database-connections';

    protected static ?string $recordTitleAttribute = 'name';

    // Navigation kommt aus der Menü-Tabelle.
    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('db_connections.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('db_connections.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return DatabaseConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DatabaseConnectionsTable::configure($table);
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
            'index' => ListDatabaseConnections::route('/'),
            'create' => CreateDatabaseConnection::route('/create'),
            'edit' => EditDatabaseConnection::route('/{record}/edit'),
        ];
    }
}
