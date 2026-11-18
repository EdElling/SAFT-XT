<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Apps\Pages\CreateApp;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Filament\Resources\Apps\Tables\AppsTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\App;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Apps (Fachanwendungen, B-107): anlegen, bearbeiten, leere löschen. Rechte apps.view und apps.manage. */
class AppResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = App::class;

    protected static ?string $slug = 'apps';

    protected static bool $shouldRegisterNavigation = false;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;
        $user = auth()->user();
        $permission = in_array($action, ['viewAny', 'view'], true) ? 'apps.view' : 'apps.manage';

        return $user instanceof User && $user->can($permission) ? Response::allow() : Response::deny();
    }

    public static function getModelLabel(): string
    {
        return __('apps.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('apps.plural');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof App ? (string) $record->name : '';
    }

    public static function form(Schema $schema): Schema
    {
        return AppForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApps::route('/'),
            'create' => CreateApp::route('/create'),
            'edit' => EditApp::route('/{record}/edit'),
        ];
    }
}
