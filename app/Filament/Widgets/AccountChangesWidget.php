<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\ActivityChanges;
use App\Filament\Widgets\Concerns\QueriesActivities;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\Activity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Änderungen anderer (Admins, SSO-Rollen-Mapping) am eigenen Konto – macht
 * nachvollziehbar, warum sich z. B. die eigenen Rechte geändert haben.
 */
class AccountChangesWidget extends TableWidget
{
    use QueriesActivities;
    use RequiresPermissions;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected static function requiredPermissions(): array
    {
        return ['widgets.account_changes'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.account_changes'))
            ->query(function (): Builder {
                $user = self::currentUser();

                return Activity::query()
                    ->where('subject_type', $user->getMorphClass())
                    ->where('subject_id', $user->getKey())
                    ->whereNotIn('event', self::AUTH_EVENTS)
                    // Eigene Änderungen stehen in "Meine letzten Änderungen".
                    ->where(fn (Builder $q) => $q
                        ->whereNull('causer_id')
                        ->orWhere('causer_type', '!=', $user->getMorphClass())
                        ->orWhere('causer_id', '!=', $user->getKey()))
                    ->with('causer')
                    ->orderByDesc('id')
                    ->limit(10);
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')->label(__('data.history.when'))->since()->dateTimeTooltip(),
                TextColumn::make('causer_label')->label(__('data.history.who'))->state(fn (Activity $record): string => ActivityChanges::causerLabel($record, __('dashboard.system_or_login'))),
                TextColumn::make('summary')
                    ->label(__('data.history.what'))
                    ->state(fn (Activity $record): string => ActivityChanges::summary($record, 100))
                    ->wrap(),
            ])
            ->emptyStateHeading(__('dashboard.no_changes_by_others'));
    }
}
