<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Filament\Support\ActivityChanges;
use App\Filament\Widgets\Concerns\QueriesActivities;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\Activity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentChangesWidget extends TableWidget
{
    use QueriesActivities;
    use RequiresPermissions;

    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermissions(): array
    {
        return ['widgets.recent_changes', 'activity_log.view'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.recent_changes'))
            ->query(fn () => Activity::query()
                ->whereNotIn('event', self::AUTH_EVENTS)
                ->with('causer')
                ->orderByDesc('id')
                ->limit(15))
            ->paginated(false)
            ->recordUrl(fn (Activity $record): string => ActivityResource::getUrl('view', ['record' => $record]))
            ->columns([
                ...ActivitiesTable::columns(),
                TextColumn::make('summary')
                    ->label(__('data.history.what'))
                    ->state(fn (Activity $record): string => ActivityChanges::summary($record, 100))
                    ->wrap(),
            ]);
    }
}
