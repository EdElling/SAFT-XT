<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/** Platzhalter für maskierte Werte im Audit-Log (Secrets, verschlüsselte Settings). */
final class AuditMask
{
    public const string VALUE = '••••••';
}
