<?php

declare(strict_types=1);

namespace App\Apps\Definition;

/**
 * Ergebnis der Prüfung vor dem Installieren einer App-Definition (AppInstaller::analyze): was angelegt wird, was das
 * Installieren verhindert und worauf hingewiesen wird. Bei einer Kopie enthält $definition die umbenannte Fassung.
 */
final readonly class InstallAnalysis
{
    /**
     * @param  array<string, mixed>  $definition  zu installierende Definition (bei einer Kopie umbenannt, neue Schlüssel)
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  array<string, int>  $counts  Anzahl je Teil (tables, fields, external_tables, report_links, reports, menus, roles, tasks, manuals)
     * @param  list<string>  $requires  genutzte Schnittstellen anderer Apps („App › Tabelle (Felder)“)
     */
    public function __construct(
        public array $definition,
        public array $blockers,
        public array $warnings,
        public array $counts,
        public bool $copy,
        public array $requires = [],
    ) {}

    public function canInstall(): bool
    {
        return $this->blockers === [];
    }
}
