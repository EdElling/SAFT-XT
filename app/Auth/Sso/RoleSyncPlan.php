<?php

declare(strict_types=1);

namespace App\Auth\Sso;

/**
 * Ergebnis der Rollen-Zuordnung für einen Login (vor dem Anwenden berechnet).
 */
final readonly class RoleSyncPlan
{
    /**
     * @param  list<int>  $managedRoleIds  Rollen, die dieser IdP verwaltet
     * @param  list<int>  $matchedRoleIds  davon zutreffend für diesen Login
     */
    public function __construct(
        public bool $applicable,
        public array $managedRoleIds = [],
        public array $matchedRoleIds = [],
    ) {}

    public static function skip(): self
    {
        return new self(false);
    }
}
