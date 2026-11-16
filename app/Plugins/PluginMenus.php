<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Models\Menu;
use Illuminate\Support\Facades\DB;

/** Menüvorschläge eines Plugins übernehmen (Seite „Plugins“). */
final class PluginMenus
{
    /** @return list<MenuSuggestion> Vorschläge, zu deren Route es noch keinen Menüeintrag gibt */
    public function missing(SaftPlugin $plugin): array
    {
        $existing = Menu::query()->whereIn('route', array_map(static fn (MenuSuggestion $s): string => $s->route, $plugin->menuSuggestions()))->pluck('route')->all();

        return array_values(array_filter($plugin->menuSuggestions(), static fn (MenuSuggestion $s): bool => ! in_array($s->route, $existing, true)));
    }

    /** Legt die fehlenden Einträge an (bei Bedarf auch die Gruppe) und liefert ihre Anzahl. */
    public function adopt(SaftPlugin $plugin): int
    {
        return DB::transaction(function () use ($plugin): int {
            $missing = $this->missing($plugin);

            foreach ($missing as $suggestion) {
                Menu::create([
                    'parent_id' => $suggestion->group === null ? null : $this->group($suggestion->group)->id,
                    'label' => $suggestion->label,
                    'route' => $suggestion->route,
                    'icon' => $suggestion->icon,
                    'permission_name' => $suggestion->permission,
                    'sort_order' => $suggestion->sortOrder,
                    'is_active' => true,
                ]);
            }

            return count($missing);
        });
    }

    /** @param  array<string, string>  $label */
    private function group(array $label): Menu
    {
        $group = Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->where('label->de', $label['de'] ?? reset($label))->first();

        return $group ?? Menu::create([
            'parent_id' => null,
            'label' => $label,
            'sort_order' => (int) Menu::query()->whereNull('parent_id')->where('sort_order', '<', 800)->max('sort_order') + 10,
            'is_active' => true,
        ]);
    }
}
