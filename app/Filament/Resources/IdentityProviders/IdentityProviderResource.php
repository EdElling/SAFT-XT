<?php

declare(strict_types=1);

namespace App\Filament\Resources\IdentityProviders;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\IdentityProviders\Pages\CreateIdentityProvider;
use App\Filament\Resources\IdentityProviders\Pages\EditIdentityProvider;
use App\Filament\Resources\IdentityProviders\Pages\ListIdentityProviders;
use App\Filament\Resources\IdentityProviders\Schemas\IdentityProviderForm;
use App\Filament\Resources\IdentityProviders\Tables\IdentityProvidersTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\IdentityProvider;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class IdentityProviderResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = IdentityProvider::class;

    protected static ?string $slug = 'identity-providers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('idp.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('idp.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return IdentityProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IdentityProvidersTable::configure($table);
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
            'index' => ListIdentityProviders::route('/'),
            'create' => CreateIdentityProvider::route('/create'),
            'edit' => EditIdentityProvider::route('/{record}/edit'),
        ];
    }
}
