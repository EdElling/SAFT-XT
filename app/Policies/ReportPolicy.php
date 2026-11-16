<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * Auswertungen: eigene mit reports.create bearbeiten und löschen, fremde nur mit
 * reports.update bzw. reports.delete. Ob eine Auswertung ausführbar ist, entscheiden
 * zusätzlich die Datenrechte (ReportRunner).
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reports.view');
    }

    public function view(User $user, Report $report): bool
    {
        return $user->can('reports.view')
            && ($report->is_shared || $report->isOwnedBy($user) || $user->can('reports.update'));
    }

    public function create(User $user): bool
    {
        return $user->can('reports.create');
    }

    public function update(User $user, Report $report): bool
    {
        return ($report->isOwnedBy($user) && $user->can('reports.create')) || $user->can('reports.update');
    }

    public function delete(User $user, Report $report): bool
    {
        return ($report->isOwnedBy($user) && $user->can('reports.create')) || $user->can('reports.delete');
    }
}
