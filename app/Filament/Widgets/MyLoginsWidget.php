<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\LoginMethod;
use App\Filament\Widgets\Concerns\QueriesActivities;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\Activity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class MyLoginsWidget extends TableWidget
{
    use QueriesActivities;
    use RequiresPermissions;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected static function requiredPermissions(): array
    {
        return ['widgets.my_logins'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.my_logins'))
            ->query(fn () => self::causedBy(self::currentUser())
                ->whereIn('event', ['login', 'logout'])
                ->orderByDesc('id')
                ->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('data.history.when'))
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('event')
                    ->label(__('activity.event'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'login' ? __('dashboard.login') : __('dashboard.logout'))
                    ->color(fn (string $state): string => $state === 'login' ? 'success' : 'gray'),
                TextColumn::make('method')
                    ->label(__('dashboard.method'))
                    ->state(fn (Activity $record): string => LoginMethod::label($record->properties['method'] ?? null)),
                TextColumn::make('ip')
                    ->label(__('dashboard.ip_column'))
                    ->state(fn (Activity $record): ?string => $record->properties['ip'] ?? null)
                    ->placeholder('—'),
            ])
            ->emptyStateHeading(__('dashboard.no_logins'));
    }
}
