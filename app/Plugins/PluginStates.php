<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Models\PluginState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Ein- und ausgeschaltete Plugins (B-030). Ohne Eintrag in plugin_states ist ein Plugin aktiv – auch solange die
 * Tabelle noch nicht angelegt ist (vor dem ersten migrate). Der Zustand wird je Anfrage einmal gelesen.
 *
 * Ein ausgeschaltetes Plugin ruht (keine Listener, Knöpfe, Prüfregeln, Formelfunktionen), seine Seiten und Kacheln
 * sind gesperrt, seine Menüeinträge ausgeblendet. Plugins, die es per composer.json brauchen, ruhen mit.
 */
final class PluginStates
{
    /** @var array<string, bool>|null Plugin-id => eingeschaltet, aus der Datenbank */
    private ?array $stored = null;

    /** @var array<string, bool> vorübergehend gesetzte Zustände (Tests: PluginTestCase::withoutPlugin) */
    private static array $overrides = [];

    public function __construct(private readonly PluginRegistry $plugins) {}

    public function isEnabled(string $id): bool
    {
        return self::$overrides[$id] ?? $this->stored()[$id] ?? true;
    }

    public const string ACTIVE = 'active';

    public const string RESTING = 'resting';

    public const string DISABLED = 'disabled';

    /** Zustand für die Anzeige: aktiv, ruht (es fehlt etwas, das es braucht) oder ausgeschaltet. */
    public function status(SaftPlugin $plugin): string
    {
        if (! $this->isEnabled($plugin->id())) {
            return self::DISABLED;
        }

        return app(PluginDependencies::class)->missing($plugin) === [] ? self::ACTIVE : self::RESTING;
    }

    /** Gehört die Klasse (Seite, Ressource, Widget, Livewire-Komponente) zu einem ausgeschalteten Plugin? */
    public function blocks(string $class): bool
    {
        $owner = $this->plugins->ownerOf($class);

        return $owner !== null && ! $this->isEnabled($owner->id());
    }

    /** Menüeintrag eines ausgeschalteten Plugins – über seine Seite (Route) oder sein Recht? */
    public function hidesMenu(?string $route, ?string $permission): bool
    {
        if (filled($permission) && ($plugin = $this->plugins->forPermission((string) $permission)) !== null && ! $this->isEnabled($plugin->id())) {
            return true;
        }

        $class = filled($route) ? Route::getRoutes()->getByName((string) $route)?->getControllerClass() : null;

        return $class !== null && $this->blocks($class);
    }

    /** @return array<string, bool> eingeschaltet je Plugin – für Zwischenspeicher, die vom Zustand abhängen */
    public function fingerprint(): array
    {
        $states = [];

        foreach (array_keys($this->plugins->all()) as $id) {
            $states[$id] = $this->isEnabled($id);
        }

        return $states;
    }

    public function enable(string $id): void
    {
        $this->store($id, true);
    }

    public function disable(string $id): void
    {
        $this->store($id, false);
    }

    /**
     * Zustand nur für die Dauer von $callback setzen, ohne Datenbank (für Tests).
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function temporarily(string $id, bool $enabled, \Closure $callback): mixed
    {
        $previous = self::$overrides;
        self::$overrides[$id] = $enabled;
        app(PluginDependencies::class)->flush();

        try {
            return $callback();
        } finally {
            self::$overrides = $previous;
            app(PluginDependencies::class)->flush();
        }
    }

    /**
     * Installierte Plugins, die dieses Plugin laut require der composer.json brauchen.
     *
     * @return list<SaftPlugin>
     */
    public function requiredBy(SaftPlugin $plugin): array
    {
        return array_values(array_filter(
            $this->plugins->all(),
            fn (SaftPlugin $other): bool => in_array($plugin->id(), array_map(static fn (SaftPlugin $p): string => $p->id(), $this->requires($other)), true),
        ));
    }

    /**
     * Installierte Plugins, die dieses Plugin laut require seiner composer.json braucht.
     *
     * @return list<SaftPlugin>
     */
    public function requires(SaftPlugin $plugin): array
    {
        $packages = array_keys((array) ($plugin->composer()['require'] ?? []));
        $required = [];

        foreach ($this->plugins->all() as $other) {
            if ($other->id() !== $plugin->id() && in_array($other->packageName(), $packages, true)) {
                $required[] = $other;
            }
        }

        return $required;
    }

    /**
     * Alle Plugins, die mitruhen, wenn dieses ausgeschaltet wird – direkt und über Zwischenstufen.
     *
     * @return list<SaftPlugin>
     */
    public function dependents(SaftPlugin $plugin): array
    {
        $found = [];
        $queue = [$plugin];

        while (($current = array_shift($queue)) !== null) {
            foreach ($this->requiredBy($current) as $dependent) {
                if ($dependent->id() !== $plugin->id() && ! isset($found[$dependent->id()])) {
                    $found[$dependent->id()] = $dependent;
                    $queue[] = $dependent;
                }
            }
        }

        ksort($found);

        return array_values($found);
    }

    private function store(string $id, bool $enabled): void
    {
        // Über das Model, damit die Änderung mit der handelnden Person im Änderungsprotokoll steht.
        $state = PluginState::query()->firstOrNew(['plugin_id' => $id]);

        if ($state->exists && $state->is_enabled === $enabled) {
            return;
        }

        $state->is_enabled = $enabled;
        $state->save();
        $this->stored = null;
        app(PluginDependencies::class)->flush();
    }

    /** @return array<string, bool> */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            return $this->stored = Schema::hasTable('plugin_states')
                ? PluginState::query()->pluck('is_enabled', 'plugin_id')->map(static fn (mixed $v): bool => (bool) $v)->all()
                : [];
        } catch (QueryException) {
            // Ohne Datenbank (z. B. beim Bauen des Caches) gelten alle Plugins als eingeschaltet.
            return [];
        }
    }
}
