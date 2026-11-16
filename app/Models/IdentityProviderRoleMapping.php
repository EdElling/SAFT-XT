<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\IdentityProviderRoleMapping as BaseIdentityProviderRoleMapping;
use App\Models\Concerns\HasAuditLog;

/**
 * Zuordnung externer Wert (Entra-Gruppe/App-Rolle, SAML-Attributwert) -> Rolle.
 */
class IdentityProviderRoleMapping extends BaseIdentityProviderRoleMapping
{
    use HasAuditLog;

    /** Vergleich ohne Groß-/Kleinschreibung und Randleerzeichen (GUIDs, LDAP-DNs). */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
