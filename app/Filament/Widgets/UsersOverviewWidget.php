<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UsersOverviewWidget extends StatsOverviewWidget
{
    use RequiresPermissions;

    protected static ?int $sort = 11;

    protected int|array|null $columns = 4;

    protected function getHeading(): ?string
    {
        return __('dashboard.users_overview');
    }

    protected static function requiredPermissions(): array
    {
        return ['widgets.users_overview', 'users.view'];
    }

    protected function getStats(): array
    {
        return [
            Stat::make(__('common.active'), (string) User::query()->where('is_active', true)->count())
                ->url(self::usersUrl(['is_active' => ['value' => '1']])),
            Stat::make(__('dashboard.inactive'), (string) User::query()->where('is_active', false)->count())
                ->url(self::usersUrl(['is_active' => ['value' => '0']])),
            Stat::make(__('users.table.without_role'), (string) ($withoutRole = User::query()->whereDoesntHave('roles')->count()))
                ->color($withoutRole > 0 ? 'warning' : null)
                ->url(self::usersUrl(['without_roles' => ['isActive' => '1']])),
            Stat::make(__('dashboard.with_sso'), (string) User::query()->whereHas('social_accounts')->count())
                ->url(self::usersUrl(['sso_linked' => ['value' => '1']])),
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $filters
     */
    private static function usersUrl(array $filters): string
    {
        return UserResource::getUrl('index', ['filters' => $filters]);
    }
}
