<?php

declare(strict_types=1);

namespace App\Apps;

use App\Models\CustomTable;
use App\Plugins\SaftPlugin;

/**
 * App eines Plugins (B-107): abgeleitet aus requiredTables() – liegen alle Tabellen, die es braucht, in einer App, gehört
 * das Plugin zu ihr (z. B. die Lager-Plugins zur App mit den Lager-Tabellen). Ohne Tabellen oder mit Tabellen aus
 * mehreren Apps gilt es für alle Apps. Eine ausdrückliche Zuordnung kommt mit Etappe 7.
 */
final class PluginApps
{
    /** @var array<string, int|null> */
    private array $cache = [];

    public function appIdFor(SaftPlugin $plugin): ?int
    {
        if (array_key_exists($plugin->id(), $this->cache)) {
            return $this->cache[$plugin->id()];
        }

        $tables = array_map('strval', array_keys($plugin->requiredTables()));
        $apps = $tables === [] ? [] : CustomTable::query()->whereIn('name', $tables)->pluck('app_id')->unique()->values()->all();

        return $this->cache[$plugin->id()] = count($apps) === 1 && $apps[0] !== null ? (int) $apps[0] : null;
    }

    /** Gehört das Plugin zur App (oder zu allen Apps)? */
    public function belongsTo(SaftPlugin $plugin, ?int $appId): bool
    {
        $own = $this->appIdFor($plugin);

        return $own === null || $own === $appId;
    }
}
