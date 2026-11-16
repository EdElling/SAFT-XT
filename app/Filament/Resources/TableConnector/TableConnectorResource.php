<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\TableConnector\Pages\CreateConnectedTable;
use App\Filament\Resources\TableConnector\Pages\EditConnectedTable;
use App\Filament\Resources\TableConnector\Pages\ListConnectedTables;
use App\Filament\Resources\TableConnector\RelationManagers\ConnectedColumnsRelationManager;
use App\Filament\Resources\TableConnector\RelationManagers\SnapshotsRelationManager;
use App\Filament\Resources\TableConnector\Schemas\ConnectedTableForm;
use App\Filament\Resources\TableConnector\Tables\ConnectedTablesTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\CustomTable;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Table-Connector: Tabellen und Views externer Datenbanken anbinden (Metadaten in custom_tables
 * mit Quelle „extern“). Eigene Rechte table_connector.*, getrennt vom Table-Designer.
 */
class TableConnectorResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = CustomTable::class;

    protected static ?string $slug = 'table-connector';

    protected static ?string $recordTitleAttribute = 'label';

    protected static bool $shouldRegisterNavigation = false;

    /** Filament-Aktion => Recht table_connector.<recht>; alles andere ist nicht vorgesehen. */
    private const array ABILITIES = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
    ];

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = self::ABILITIES[$action instanceof UnitEnum ? $action->name : $action] ?? null;
        $user = auth()->user();

        return $ability !== null && $user instanceof User && $user->can("table_connector.{$ability}")
            ? Response::allow()
            : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->external();
    }

    public static function getModelLabel(): string
    {
        return __('table_connector.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('table_connector.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ConnectedTableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConnectedTablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ConnectedColumnsRelationManager::class,
            SnapshotsRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnectedTables::route('/'),
            'create' => CreateConnectedTable::route('/create'),
            'edit' => EditConnectedTable::route('/{record}/edit'),
        ];
    }
}
