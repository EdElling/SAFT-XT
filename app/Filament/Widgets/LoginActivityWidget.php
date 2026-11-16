<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\Activity;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class LoginActivityWidget extends StatsOverviewWidget
{
    use RequiresPermissions;

    protected static ?int $sort = 10;

    protected function getHeading(): ?string
    {
        return __('dashboard.login_activity');
    }

    protected static function requiredPermissions(): array
    {
        return ['widgets.login_activity', 'users.view'];
    }

    protected function getStats(): array
    {
        $since = now()->subDays(7);
        $logins = Activity::query()->where('event', 'login')->where('created_at', '>=', $since);

        $total = (clone $logins)->count();
        // JSON-Pfad über den Query Builder: portabel für alle unterstützten Datenbanken.
        $sso = (clone $logins)->where('properties->method', 'like', 'sso:%')->count();

        $failed = Activity::query()->where('event', 'login_failed')->where('created_at', '>=', now()->subDay())->count();

        $stats = [
            Stat::make(__('dashboard.logins_7_days'), (string) $total)
                ->description(__('dashboard.logins_split', ['password' => $total - $sso, 'sso' => $sso]))
                ->descriptionIcon('heroicon-o-arrow-right-end-on-rectangle'),
            Stat::make(__('dashboard.failed_24h'), (string) $failed)
                ->color($failed > 0 ? 'danger' : 'success')
                ->descriptionIcon($failed > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->description($failed > 0 ? __('dashboard.failed_24h_help') : __('common.none')),
        ];

        if (config('session.driver') === 'database') {
            $active = DB::table(config('session.table', 'sessions'))
                ->whereNotNull('user_id')
                ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
                ->distinct()
                ->count('user_id');

            $stats[] = Stat::make(__('dashboard.signed_in_users'), (string) $active)
                ->description(__('dashboard.signed_in_users_help'))
                ->descriptionIcon('heroicon-o-users');
        }

        return $stats;
    }
}
