<?php

declare(strict_types=1);

namespace App\Plugins;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\Models\CustomTable;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\DataTable;
use App\Plugins\Api\Events\RecordEvent;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use Closure;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * Installierte Plugins. Jedes Plugin meldet sich in seinem PluginServiceProvider an – vor dem Aufbau des Panels,
 * das die Plugins dann einbindet (AdminPanelProvider).
 */
final class PluginRegistry
{
    /** @var array<string, SaftPlugin> */
    private array $plugins = [];

    public function add(SaftPlugin $plugin): void
    {
        $id = $plugin->id();

        if (preg_match(SaftPlugin::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("Ungültige Plugin-id „{$id}“: nur Kleinbuchstaben, Ziffern und Bindestriche.");
        }

        if (isset($this->plugins[$id]) && $plugin::class !== $this->plugins[$id]::class) {
            throw new InvalidArgumentException("Plugin-id „{$id}“ ist doppelt vergeben (".$this->plugins[$id]::class.', '.$plugin::class.').');
        }

        $this->plugins[$id] = $plugin;
    }

    /** @return array<string, SaftPlugin> nach id sortiert */
    public function all(): array
    {
        ksort($this->plugins);

        return $this->plugins;
    }

    public function has(string $id): bool
    {
        return isset($this->plugins[$id]);
    }

    public function get(string $id): SaftPlugin
    {
        return $this->plugins[$id] ?? throw new InvalidArgumentException("Plugin „{$id}“ ist nicht installiert.");
    }

    /** Plugin, zu dem eine Klasse gehört (Seite, Ressource, Widget …): das mit dem längsten passenden Namensraum. */
    public function ownerOf(string $class): ?SaftPlugin
    {
        $owner = null;
        $length = 0;

        foreach ($this->plugins as $plugin) {
            $namespace = (new \ReflectionClass($plugin))->getNamespaceName().'\\';

            if (str_starts_with(ltrim($class, '\\'), $namespace) && strlen($namespace) > $length) {
                [$owner, $length] = [$plugin, strlen($namespace)];
            }
        }

        return $owner;
    }

    /** Plugin, zu dem ein Recht gehört („lager-umbuchung.execute“ → Plugin „lager-umbuchung“). */
    public function forPermission(string $permission): ?SaftPlugin
    {
        $plugin = $this->plugins[strstr($permission, '.', true) ?: $permission] ?? null;

        return $plugin !== null && in_array($permission, $plugin->permissionNames(), true) ? $plugin : null;
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_merge([], ...array_values(array_map(static fn (SaftPlugin $p): array => $p->permissionNames(), $this->all())));
    }

    /** Listener eines Plugins anmelden – je nur für ihre Tabelle; eine Klasse als Handler entsteht erst beim Ereignis. */
    public function listen(SaftPlugin $plugin): void
    {
        foreach ($plugin->recordListeners() as $listener) {
            Event::listen($listener->event, static function (RecordEvent $event) use ($listener, $plugin): void {
                // Fehlen Tabellen oder Felder, die das Plugin braucht, ruht es.
                if ($event->table === $listener->table && app(PluginDependencies::class)->satisfied($plugin)) {
                    ($listener->handler instanceof Closure ? $listener->handler : app($listener->handler))($event);
                }
            });
        }
    }

    /**
     * Aktionen der Plugins für die Datenansicht einer Tabelle.
     *
     * @param  DataAction::TABLE|DataAction::RECORD  $placement
     * @return list<Action>
     */
    public function dataActions(CustomTable $table, string $placement): array
    {
        $actions = [];

        foreach ($this->all() as $plugin) {
            if ($plugin->dataActions() === [] || ! app(PluginDependencies::class)->satisfied($plugin)) {
                continue;
            }

            foreach ($plugin->dataActions() as $action) {
                if ($action->table === $table->name && $action->placement === $placement) {
                    $actions[] = ($action->make)(new DataTable($table));
                }
            }
        }

        return $actions;
    }

    /**
     * Formelfunktionen der Plugins, die nicht ruhen, nach Name. Ungültige Namen, eingebaute Funktionen und doppelte
     * Namen (das zweite Plugin nach id) werden übergangen – saft:plugins --check meldet sie.
     *
     * @return array<string, FormulaFunction>
     */
    public function formulaFunctions(): array
    {
        $reserved = [...array_keys(FormulaAnalyzer::FUNCTIONS), ...FormulaAnalyzer::AGGREGATES];
        $functions = [];

        foreach ($this->all() as $plugin) {
            if ($plugin->formulaFunctions() === [] || ! app(PluginDependencies::class)->satisfied($plugin)) {
                continue;
            }

            foreach ($plugin->formulaFunctions() as $function) {
                if (preg_match(FormulaFunction::NAME_PATTERN, $function->name) === 1
                    && ! in_array($function->name, $reserved, true) && ! isset($functions[$function->name])) {
                    $functions[$function->name] = $function;
                }
            }
        }

        return $functions;
    }

    /**
     * Prüfregeln der Plugins, die nicht ruhen, nach Schlüssel „<plugin-id>.<name>“.
     *
     * @param  string|null  $type  nur Regeln für diesen Feldtyp
     * @return array<string, FieldRule>
     */
    public function fieldRules(?string $type = null): array
    {
        $rules = [];

        foreach ($this->all() as $plugin) {
            if ($plugin->fieldRules() === [] || ! app(PluginDependencies::class)->satisfied($plugin)) {
                continue;
            }

            foreach ($plugin->fieldRules() as $rule) {
                if (preg_match(FieldRule::NAME_PATTERN, $rule->name) === 1
                    && ($type === null || (in_array($type, $rule->types, true) && in_array($type, FieldRule::TYPES, true)))) {
                    $rules[$plugin->id().'.'.$rule->name] ??= $rule;
                }
            }
        }

        return $rules;
    }

    /** @return list<string> Klassen der Aufgaben-Arten aller Plugins */
    public function taskTypes(): array
    {
        return array_merge([], ...array_values(array_map(static fn (SaftPlugin $p): array => $p->taskTypes(), $this->all())));
    }
}
