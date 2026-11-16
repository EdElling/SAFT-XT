<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Models\Menu;
use Illuminate\Support\Collection;

/**
 * In-Memory-Sicht auf den Menübaum (eine Query) für Pfade, Tiefen und
 * Zyklen-/Tiefenprüfung beim Verschieben von Einträgen.
 */
final class MenuTree
{
    public const int MAX_DEPTH = 3;

    /** @var array<int, int|null> id => parent_id */
    private array $parents = [];

    /** @var array<int, string> id => label */
    private array $labels = [];

    /** @var array<int, list<int>> parent_id => child ids */
    private array $children = [];

    /**
     * @param  Collection<int, Menu>  $menus
     */
    public function __construct(Collection $menus)
    {
        foreach ($menus as $menu) {
            $this->parents[$menu->id] = $menu->parent_id;
            $this->labels[$menu->id] = $menu->label;

            if ($menu->parent_id !== null) {
                $this->children[$menu->parent_id][] = $menu->id;
            }
        }
    }

    public static function fromDatabase(): self
    {
        return new self(
            Menu::query()->orderBy('sort_order')->orderedByLabel()->get(['id', 'parent_id', 'label']),
        );
    }

    /**
     * @return list<string> Labels von der Wurzel bis zum Eintrag.
     */
    public function path(int $id): array
    {
        $path = [];
        $visited = [];

        for ($current = $id; $current !== null && isset($this->labels[$current]); $current = $this->parents[$current]) {
            if (isset($visited[$current])) {
                break; // Schutz gegen inkonsistente Daten (Zyklus)
            }

            $visited[$current] = true;
            array_unshift($path, $this->labels[$current]);
        }

        return $path;
    }

    public function depth(int $id): int
    {
        return count($this->path($id));
    }

    /**
     * Höhe des Teilbaums inkl. Eintrag selbst (Blatt = 1).
     */
    public function height(int $id): int
    {
        $max = 0;

        foreach ($this->children[$id] ?? [] as $child) {
            $max = max($max, $this->height($child));
        }

        return $max + 1;
    }

    /**
     * @return list<int> Eintrag selbst und alle Nachfahren.
     */
    public function subtreeIds(int $id): array
    {
        $ids = [$id];

        foreach ($this->children[$id] ?? [] as $child) {
            array_push($ids, ...$this->subtreeIds($child));
        }

        return $ids;
    }

    /**
     * Prüft, ob $id (bzw. ein neuer Eintrag bei null) unter $parentId gehängt
     * werden darf, ohne Zyklus und ohne MAX_DEPTH zu überschreiten.
     */
    public function canAttach(?int $id, ?int $parentId): bool
    {
        if ($parentId === null) {
            return $id === null || $this->height($id) <= self::MAX_DEPTH;
        }

        if (! isset($this->labels[$parentId])) {
            return false;
        }

        if ($id !== null && in_array($parentId, $this->subtreeIds($id), true)) {
            return false;
        }

        $height = $id === null ? 1 : $this->height($id);

        return $this->depth($parentId) + $height <= self::MAX_DEPTH;
    }

    /**
     * @return array<int, string> Zulässige übergeordnete Einträge als "A › B".
     */
    public function parentOptions(?int $forId = null): array
    {
        $options = [];

        foreach (array_keys($this->labels) as $candidate) {
            if ($this->canAttach($forId, $candidate)) {
                $options[$candidate] = implode(' › ', $this->path($candidate));
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
