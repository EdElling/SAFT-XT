<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Gemeinsame Abfragen auf das Audit-Log für Dashboard-Widgets. */
trait QueriesActivities
{
    /** Ereignisse rund um An-/Abmeldung (nicht als "Änderungen" anzeigen). */
    protected const array AUTH_EVENTS = ['login', 'logout', 'login_failed'];

    /**
     * @return Builder<Activity>
     */
    protected static function causedBy(User $user): Builder
    {
        return Activity::query()
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey());
    }

    protected static function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
