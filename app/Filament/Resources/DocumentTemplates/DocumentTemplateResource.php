<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates;

use App\Apps\AppPermissions;
use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Filament\Resources\DocumentTemplates\Schemas\DocumentTemplateForm;
use App\Filament\Resources\DocumentTemplates\Tables\DocumentTemplatesTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\App;
use App\Models\DocumentTemplate;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Inhaltsvorlagen für Dokumente (B-124, D1b), je App. Verwalten darf, wer in der App das Recht documents.manage hat
 * (App-Rolle oder Systemrolle; Super-Admin überall). Liste und Auswahl zeigen nur diese Apps.
 */
class DocumentTemplateResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = DocumentTemplate::class;

    protected static ?string $slug = 'document-templates';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

    public const string PERMISSION = 'documents.manage';

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return Response::deny();
        }

        $allowed = $record instanceof DocumentTemplate
            ? AppPermissions::can($user, self::PERMISSION, $record->app_id)
            : self::appIds($user) !== [];

        return $allowed ? Response::allow() : Response::deny();
    }

    /**
     * Apps, deren Vorlagen die Person verwalten darf.
     *
     * @return list<int>
     */
    public static function appIds(User $user): array
    {
        return once(static fn (): array => App::query()->orderBy('sort_order')->orderBy('id')->pluck('id')
            ->filter(static fn (int $id): bool => AppPermissions::can($user, self::PERMISSION, $id))
            ->values()->all());
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->whereIn('app_id', $user instanceof User ? self::appIds($user) : []);
    }

    public static function getModelLabel(): string
    {
        return __('documents.templates.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documents.templates.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTemplates::route('/'),
            'create' => CreateDocumentTemplate::route('/create'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
        ];
    }
}
