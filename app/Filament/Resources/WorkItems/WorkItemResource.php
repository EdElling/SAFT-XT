<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkItems;

use App\Filament\Resources\WorkItems\Pages\ListWorkItems;
use App\Filament\Resources\WorkItems\Pages\ViewWorkItem;
use App\Filament\Resources\WorkItems\Schemas\WorkItemInfolist;
use App\Filament\Resources\WorkItems\Tables\WorkItemsTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\User;
use App\Models\WorkItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Seite „Arbeiten“ (Fundament 2, F3a): eigene Arbeiten für alle („Meine Arbeiten“ im Benutzermenü), alle mit
 * work.view_any (Administration › Betrieb › Arbeiten). Nur lesen, abbrechen und Ergebnisse herunterladen.
 */
class WorkItemResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = WorkItem::class;

    protected static ?string $slug = 'work-items';

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('work.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('work.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkItemsTable::configure($table);
    }

    /** Ohne work.view_any nur die eigenen Arbeiten. */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->with(['user', 'app'])
            ->when(! ($user instanceof User && $user->can('work.view_any')), static fn (Builder $query): Builder => $query->where('user_id', $user?->getAuthIdentifier()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkItems::route('/'),
            'view' => ViewWorkItem::route('/{record}'),
        ];
    }
}
