<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\LoginMethod;
use App\Filament\Widgets\Concerns\QueriesActivities;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\Activity;
use App\Support\AppLocale;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vorherige Anmeldung des Benutzers (die aktuelle kennt er selbst) und
 * fehlgeschlagene Versuche seither – zum Erkennen fremder Zugriffe.
 */
class LastLoginWidget extends StatsOverviewWidget
{
    use QueriesActivities;
    use RequiresPermissions;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    protected int|array|null $columns = 1;

    protected function getHeading(): ?string
    {
        return __('dashboard.last_login');
    }

    protected function getDescription(): ?string
    {
        return __('dashboard.last_login_description');
    }

    protected static function requiredPermissions(): array
    {
        return ['widgets.last_login'];
    }

    protected function getStats(): array
    {
        $user = self::currentUser();

        /** @var Activity|null $previous */
        $previous = self::causedBy($user)->where('event', 'login')->orderByDesc('id')->skip(1)->first();

        $failed = Activity::query()
            ->where('event', 'login_failed')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->when($previous, fn ($q) => $q->where('created_at', '>', $previous->created_at))
            ->count();

        return [
            $previous === null
                ? Stat::make(__('dashboard.previous_login'), '—')->description(__('dashboard.first_login'))
                : Stat::make(__('dashboard.previous_login'), $previous->created_at->diffForHumans())
                    ->description(implode(' · ', array_filter([
                        AppLocale::dateTime($previous->created_at),
                        LoginMethod::label($previous->properties['method'] ?? null),
                        isset($previous->properties['ip']) ? __('dashboard.ip', ['ip' => $previous->properties['ip']]) : null,
                    ])))
                    ->descriptionIcon('heroicon-o-clock'),
            Stat::make(__('dashboard.failed_since'), (string) $failed)
                ->description($failed > 0 ? __('dashboard.failed_since_help') : __('common.none'))
                ->color($failed > 0 ? 'danger' : 'success')
                ->descriptionIcon($failed > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),
        ];
    }
}
