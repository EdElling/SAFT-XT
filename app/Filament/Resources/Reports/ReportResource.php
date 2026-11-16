<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ReportDesigner;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\Report;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Auswertungen (Report-Engine). Im Menü über die menus-Tabelle, nicht über Filament. */
class ReportResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = Report::class;

    protected static ?string $slug = 'reports';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

    public const string VIEW_ROUTE = 'filament.admin.resources.reports.view';

    public static function getModelLabel(): string
    {
        return __('reports.ui.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.ui.plural');
    }

    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
    }

    /** Eigene und geteilte (bzw. alle mit reports.update). */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when($user instanceof User, fn (Builder $q) => $q->visibleTo($user));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'create' => ReportDesigner::route('/create'),
            'view' => ViewReport::route('/{record}'),
            'edit' => ReportDesigner::route('/{record}/edit'),
        ];
    }
}
