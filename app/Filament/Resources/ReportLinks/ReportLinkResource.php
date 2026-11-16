<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportLinks;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\ReportLinks\Pages\CreateReportLink;
use App\Filament\Resources\ReportLinks\Pages\EditReportLink;
use App\Filament\Resources\ReportLinks\Pages\ListReportLinks;
use App\Filament\Resources\ReportLinks\Schemas\ReportLinkForm;
use App\Filament\Resources\ReportLinks\Tables\ReportLinksTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\ReportLink;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Tabellenverknüpfungen für Auswertungen und Master-Detail (auch zwischen Quellen). Rechte report_links.*. */
class ReportLinkResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = ReportLink::class;

    protected static ?string $slug = 'report-links';

    protected static ?string $recordTitleAttribute = 'label';

    protected static bool $shouldRegisterNavigation = false;

    /** Filament-Aktion => Recht report_links.<recht> */
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

        return $ability !== null && $user instanceof User && $user->can("report_links.{$ability}")
            ? Response::allow()
            : Response::deny();
    }

    public static function getModelLabel(): string
    {
        return __('report_links.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('report_links.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ReportLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportLinksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportLinks::route('/'),
            'create' => CreateReportLink::route('/create'),
            'edit' => EditReportLink::route('/{record}/edit'),
        ];
    }
}
