<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksResourcePermissions;

/** Table-Designer: Struktur von Tabellen (nicht deren Datensätze, siehe data.<tabelle>.*). */
class CustomTablePolicy
{
    use ChecksResourcePermissions;

    protected function permissionPrefix(): string
    {
        return 'custom_tables';
    }

    /** Neue Tabelle samt Daten aus einer CSV-/Excel-Datei anlegen. */
    public function import(User $user): bool
    {
        return $this->hasResourcePermission($user, 'import');
    }
}
