<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Apps\PluginApps;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\Models\CustomTable;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\DataTable;
use App\Plugins\Api\Events\RecordEvent;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use App\Work\Types\BulkRecordsWork;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
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
     * Aktionen der Plugins für die Datenansicht einer Tabelle. Sammelaktionen (BULK) nur bei Tabellen mit einfachem
     * Schlüssel und nur, wenn das Plugin eine BulkAction liefert (sonst gemeldet und übergangen).
     *
     * @param  DataAction::TABLE|DataAction::RECORD|DataAction::BULK  $placement
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
                    if ($placement === DataAction::BULK && ! $table->hasSingleKey()) {
                        continue;
                    }

                    $made = ($action->make)(new DataTable($table));

                    if ($placement === DataAction::BULK) {
                        if (! $made instanceof BulkAction) {
                            report(new InvalidArgumentException("Plugin {$plugin->id()}: DataAction::BULK für die Tabelle {$table->name} muss eine ".BulkAction::class.' liefern, nicht '.$made::class.'.'));

                            continue;
                        }

                        self::guardBulk($made);
                    }

                    // Herkunft im Tooltip (B-063), an einen eigenen Tooltip des Plugins angehängt. Dessen Wert erst beim
                    // Anzeigen auswerten: Er kann vom Datensatz der Zeile abhängen.
                    $origin = __('plugins.origin.tooltip', ['plugin' => $plugin->label()]);
                    $own = (fn (): mixed => $this->tooltip)->call($made);
                    $made->tooltip(static function (Action $action) use ($own, $origin): string {
                        $text = $own === null ? '' : trim((string) $action->evaluate($own));

                        return $text !== '' ? $text.' · '.$origin : $origin;
                    });

                    $actions[] = $made;
                }
            }
        }

        return $actions;
    }

    /** Plugin, das eine Formelfunktion mitbringt (für den Hinweis im Formel-Editor). */
    public function formulaFunctionPlugin(string $name): ?SaftPlugin
    {
        foreach ($this->all() as $plugin) {
            foreach ($plugin->formulaFunctions() as $function) {
                if ($function->name === $name) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    /**
     * Formelfunktionen der Plugins, die nicht ruhen, nach Name. Ungültige Namen, eingebaute Funktionen und doppelte
     * Namen (das zweite Plugin nach id) werden übergangen – saft:plugins --check meldet sie.
     *
     * @return array<string, FormulaFunction>
     */
    public function formulaFunctions(?int $appId = null): array
    {
        $reserved = [...array_keys(FormulaAnalyzer::FUNCTIONS), ...FormulaAnalyzer::AGGREGATES, ...FormulaAnalyzer::KEYWORDS];
        $functions = [];

        foreach ($this->all() as $plugin) {
            if ($plugin->formulaFunctions() === [] || ! app(PluginDependencies::class)->satisfied($plugin) || ! $this->offeredIn($plugin, $appId)) {
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
     * @param  int|null  $appId  nur Plugins dieser App bzw. ohne eigene App (Angebot im Table-Designer)
     * @return array<string, FieldRule>
     */
    public function fieldRules(?string $type = null, ?int $appId = null): array
    {
        $rules = [];

        foreach ($this->all() as $plugin) {
            if ($plugin->fieldRules() === [] || ! app(PluginDependencies::class)->satisfied($plugin) || ! $this->offeredIn($plugin, $appId)) {
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

    /**
     * Wird ein Beitrag des Plugins in der App angeboten (B-107)? Ohne App (null) überall; sonst nur in der App des
     * Plugins bzw. überall, wenn es keine eigene App hat. Prüfen und Ausführen bleiben unabhängig davon.
     */
    public function offeredIn(SaftPlugin $plugin, ?int $appId): bool
    {
        return $appId === null || app(PluginApps::class)->belongsTo($plugin, $appId);
    }

    /** @return list<string> Klassen der Aufgaben-Arten aller Plugins */
    public function taskTypes(): array
    {
        return array_merge([], ...array_values(array_map(static fn (SaftPlugin $p): array => $p->taskTypes(), $this->all())));
    }

    /** @return list<string> Klassen der Arten von Arbeiten aller Plugins */
    public function workTypes(): array
    {
        return array_merge([], ...array_values(array_map(static fn (SaftPlugin $p): array => $p->workTypes(), $this->all())));
    }

    /**
     * Rahmen für Sammelaktionen aus Plugins (B-133 S4b): ausgeblendet, sobald der Papierkorb-Filter gesetzt ist (nie
     * gelöschte Datensätze), Obergrenze APP_DATA_BULK_MAX vor der Ausführung, Auswahl danach aufheben. Eigene Hooks des
     * Plugins (hidden, before) bleiben erhalten und laufen danach.
     */
    private static function guardBulk(BulkAction $action): void
    {
        $ownHidden = (fn (): mixed => $this->isHidden)->call($action);
        $ownBefore = (fn (): ?Closure => $this->before)->call($action);

        $action
            ->hidden(static function (BulkAction $action, mixed $livewire) use ($ownHidden): bool {
                $trashed = $livewire instanceof HasTable ? ($livewire->getTableFilterState(TrashedFilter::class) ?? []) : [];

                return filled($trashed['value'] ?? null) || (bool) $action->evaluate($ownHidden);
            })
            ->before(static function (BulkAction $action) use ($ownBefore): mixed {
                $count = $action->getSelectedRecordsQuery()->count();

                if (BulkRecordsWork::max() > 0 && $count > BulkRecordsWork::max()) {
                    Notification::make()->danger()->title(__('data.bulk.errors.too_many_action', ['count' => $count, 'max' => BulkRecordsWork::max()]))->send();

                    throw new Halt;
                }

                return $ownBefore === null ? null : $action->evaluate($ownBefore);
            })
            ->deselectRecordsAfterCompletion();
    }
}
