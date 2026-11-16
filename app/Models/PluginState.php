<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\PluginState as BasePluginState;
use App\Models\Concerns\HasAuditLog;

/**
 * Ein- oder ausgeschaltetes Plugin (Seite „Plugins“, saft:plugins --enable/--disable). Ohne Eintrag ist ein Plugin
 * aktiv. Schreiben nur über App\Plugins\PluginStates; jede Änderung steht im Änderungsprotokoll.
 */
class PluginState extends BasePluginState
{
    use HasAuditLog;

    protected $attributes = [
        'is_enabled' => true,
    ];
}
