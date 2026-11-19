<?php

declare(strict_types=1);

namespace App\Policies;

use App\Apps\AppPermissions;
use App\Models\Report;
use App\Models\User;

/**
 * Auswertungen: eigene mit reports.create bearbeiten und löschen, fremde nur mit
 * reports.update bzw. reports.delete. Ob eine Auswertung ausführbar ist, entscheiden
 * zusätzlich die Datenrechte (ReportRunner). Die Rechte gelten je App (B-107): für die Liste und
 * neue Auswertungen in der aktuellen App, für eine bestehende in der App ihrer Grundtabelle.
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return AppPermissions::current($user, 'reports.view');
    }

    public function view(User $user, Report $report): bool
    {
        return $this->can($user, 'reports.view', $report)
            && ($report->is_shared || $report->isOwnedBy($user) || $this->can($user, 'reports.update', $report));
    }

    public function create(User $user): bool
    {
        return AppPermissions::current($user, 'reports.create');
    }

    public function update(User $user, Report $report): bool
    {
        return ($report->isOwnedBy($user) && $this->can($user, 'reports.create', $report)) || $this->can($user, 'reports.update', $report);
    }

    public function delete(User $user, Report $report): bool
    {
        return ($report->isOwnedBy($user) && $this->can($user, 'reports.create', $report)) || $this->can($user, 'reports.delete', $report);
    }

    private function can(User $user, string $permission, Report $report): bool
    {
        return AppPermissions::can($user, $permission, $report->app_id);
    }
}
