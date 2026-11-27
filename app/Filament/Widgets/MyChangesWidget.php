<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Activities\Schemas\ActivityEventColor;
use App\Filament\Support\ActivityChanges;
use App\Filament\Support\RecordLink;
use App\Filament\Widgets\Concerns\PrimesLoggedChanges;
use App\Filament\Widgets\Concerns\QueriesActivities;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\Activity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Vom Benutzer selbst vorgenommene Änderungen (nur eigene Einträge). */
class MyChangesWidget extends TableWidget
{
    use PrimesLoggedChanges;
    use QueriesActivities;
    use RequiresPermissions;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected static function requiredPermissions(): array
    {
        return ['widgets.my_changes'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.my_changes'))
            ->query(fn () => self::causedBy(self::currentUser())
                ->whereNotIn('event', self::AUTH_EVENTS)
                ->with('subject')
                ->orderByDesc('id')
                ->limit(10))
            ->paginated(false)
            ->recordUrl(fn (Activity $record): ?string => RecordLink::forActivity($record))
            ->columns([
                TextColumn::make('created_at')->label(__('data.history.when'))->since()->dateTimeTooltip(),
                TextColumn::make('event')
                    ->label(__('dashboard.action'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Activity $record): string => ActivityChanges::eventText($record))
                    ->color(fn (?string $state): string => ActivityEventColor::for($state)),
                TextColumn::make('subject_type')
                    ->label(__('activity.subject'))
                    ->state(fn (Activity $record): string => ActivityChanges::subjectLabel($record)),
                TextColumn::make('summary')
                    ->label(__('data.history.what'))
                    ->state(fn (Activity $record): string => ActivityChanges::summary($record, 80))
                    ->wrap(),
            ])
            ->emptyStateHeading(__('dashboard.no_own_changes'));
    }
}
