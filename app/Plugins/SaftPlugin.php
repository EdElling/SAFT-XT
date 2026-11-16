<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Plugins\Api\ApiManifest;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use App\Plugins\Api\RecordListener;
use App\Tasks\TaskType;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Filament\Contracts\Plugin;
use Filament\Panel;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Beschreibung eines SAFT-Plugins (Composer-Paket unter plugins/ oder aus einem eigenen Repository).
 *
 * Ein Plugin bringt Filament-Seiten, -Ressourcen und -Widgets mit (gefunden unter src/Pages, src/Resources,
 * src/Widgets neben dieser Klasse), meldet seine Rechte und Menüvorschläge an und kann Arten geplanter
 * Aufgaben beisteuern. Rechte, Routen und Übersetzungen tragen die id als Präfix, damit sich Plugins nicht
 * in die Quere kommen. Angemeldet wird es über einen PluginServiceProvider.
 */
abstract class SaftPlugin implements Plugin
{
    /** Erlaubte id: Kleinbuchstaben, Ziffern, Bindestriche, z. B. „lager-umbuchung“. */
    public const string ID_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /** @var array<string, mixed>|null */
    private ?array $composer = null;

    private ?string $basePath = null;

    /** Eindeutige id, zugleich Präfix für Rechte („lager-umbuchung.execute“) und Übersetzungen. */
    abstract public function id(): string;

    /** Name in der aktuellen Sprache. */
    abstract public function label(): string;

    /** Kurze Beschreibung in der aktuellen Sprache. */
    public function description(): string
    {
        return (string) ($this->composer()['description'] ?? '');
    }

    /**
     * Rechte des Plugins als Aktion => Bezeichnung in der aktuellen Sprache, z. B. ['execute' => 'Ausführen'].
     * Der Name des Rechts ist „<id>.<aktion>“ (siehe permissionName()).
     *
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return [];
    }

    /** @return list<MenuSuggestion> Einträge, die die Administration mit einem Klick ins Menü übernehmen kann */
    public function menuSuggestions(): array
    {
        return [];
    }

    /** @return list<class-string<TaskType>> zusätzliche Arten geplanter Aufgaben */
    public function taskTypes(): array
    {
        return [];
    }

    /**
     * Tabellen und Felder des Table-Designers, die das Plugin braucht: Tabelle => technische Namen der Felder.
     * Fehlt etwas oder ist es deaktiviert, ruht das Plugin (keine Listener, keine Aktionen) und die Seite „Plugins“
     * warnt; der Table-Designer verhindert, dass diese Tabellen und Felder gelöscht, deaktiviert, umbenannt oder in
     * einen anderen Typ geändert werden.
     *
     * @return array<string, list<string>>
     */
    public function requiredTables(): array
    {
        return [];
    }

    /** @return list<string> was von requiredTables() fehlt oder deaktiviert ist (leer = alles da) */
    final public function missingDependencies(): array
    {
        return app(PluginDependencies::class)->missing($this);
    }

    /**
     * Ist das Plugin eingeschaltet (Administration › Plugins)? Ein ausgeschaltetes Plugin wirkt nicht; Plugins, die es
     * per composer.json brauchen, ruhen mit. Für ein optionales anderes Plugin: Saft::plugin('…')->isEnabled().
     */
    final public function isEnabled(): bool
    {
        return app(PluginStates::class)->isEnabled($this->id());
    }

    /** @return list<RecordListener> Listener für Änderungen an Tabellen (Ereignisse RecordSaving, RecordSaved …) */
    public function recordListeners(): array
    {
        return [];
    }

    /** @return list<DataAction> zusätzliche Aktionen in Datenansichten */
    public function dataActions(): array
    {
        return [];
    }

    /** @return list<FormulaFunction> zusätzliche Funktionen für berechnete Felder */
    public function formulaFunctions(): array
    {
        return [];
    }

    /** @return list<FieldRule> zusätzliche Prüfregeln für Felder (Table-Designer, „Weitere Prüfungen“) */
    public function fieldRules(): array
    {
        return [];
    }

    final public function permissionName(string $action): string
    {
        return $this->id().'.'.$action;
    }

    /** @return list<string> vollständige Namen aller Rechte */
    final public function permissionNames(): array
    {
        return array_map($this->permissionName(...), array_keys($this->permissions()));
    }

    public function getId(): string
    {
        return $this->id();
    }

    /** Seiten, Ressourcen und Widgets aus den Unterordnern neben der Plugin-Klasse. */
    public function register(Panel $panel): void
    {
        $reflection = new ReflectionClass($this);
        $directory = dirname((string) $reflection->getFileName());
        $namespace = $reflection->getNamespaceName();

        if (is_dir($directory.'/Resources')) {
            $panel->discoverResources(in: $directory.'/Resources', for: $namespace.'\\Resources');
        }

        if (is_dir($directory.'/Pages')) {
            $panel->discoverPages(in: $directory.'/Pages', for: $namespace.'\\Pages');
        }

        if (is_dir($directory.'/Widgets')) {
            $panel->discoverWidgets(in: $directory.'/Widgets', for: $namespace.'\\Widgets');
        }
    }

    public function boot(Panel $panel): void {}

    /** Ordner des Pakets (mit der composer.json). */
    final public function basePath(): string
    {
        if ($this->basePath !== null) {
            return $this->basePath;
        }

        $directory = dirname((string) (new ReflectionClass($this))->getFileName());

        while (! is_file($directory.'/composer.json')) {
            $parent = dirname($directory);

            if ($parent === $directory) {
                throw new RuntimeException('Keine composer.json für das Plugin '.static::class.' gefunden.');
            }

            $directory = $parent;
        }

        return $this->basePath = $directory;
    }

    /** Installationsort: Datei der Plugin-Klasse relativ zum Hauptverzeichnis von SAFT, z. B. „plugins/…/src/…Plugin.php“. */
    final public function location(): string
    {
        $file = (string) (new ReflectionClass($this))->getFileName();
        $real = realpath($file) ?: $file;
        $base = rtrim((string) (realpath(base_path()) ?: base_path()), '/').'/';

        return str_starts_with($real, $base) ? substr($real, strlen($base)) : $real;
    }

    /** @return array<string, mixed> Inhalt der composer.json des Pakets */
    final public function composer(): array
    {
        if ($this->composer === null) {
            $decoded = json_decode((string) file_get_contents($this->basePath().'/composer.json'), true);
            $this->composer = is_array($decoded) ? $decoded : [];
        }

        return $this->composer;
    }

    final public function packageName(): string
    {
        return (string) ($this->composer()['name'] ?? $this->id());
    }

    /** Installierte Version laut Composer, sonst aus der composer.json. */
    public function version(): string
    {
        try {
            $version = InstalledVersions::isInstalled($this->packageName()) ? InstalledVersions::getPrettyVersion($this->packageName()) : null;
        } catch (Throwable) {
            $version = null;
        }

        return (string) ($this->composer()['version'] ?? $version ?? '');
    }

    /** Benötigte SAFT-Version als Composer-Bedingung (extra.saft.requires), z. B. „^0.1“. */
    final public function requires(): ?string
    {
        $requires = $this->composer()['extra']['saft']['requires'] ?? null;

        return is_string($requires) && $requires !== '' ? $requires : null;
    }

    /** Benötigte Version der Plugin-Schnittstelle als Composer-Bedingung (extra.saft.api), z. B. „^1.0“. Vorzuziehen. */
    final public function requiresApi(): ?string
    {
        $requires = $this->composer()['extra']['saft']['api'] ?? null;

        return is_string($requires) && $requires !== '' ? $requires : null;
    }

    /**
     * true/false nach extra.saft.api (eine der bereitgestellten API-Versionen passt) und extra.saft.requires (SAFT-
     * Version); null, wenn das Plugin nichts angibt oder eine Angabe ungültig ist.
     */
    final public function isCompatible(): ?bool
    {
        $api = $this->requiresApi();
        $saft = $this->requires();

        if ($api === null && $saft === null) {
            return null;
        }

        try {
            $apiOk = $api === null || array_filter(ApiManifest::versions(), static fn (string $v): bool => Semver::satisfies($v, $api)) !== [];

            return $apiOk && ($saft === null || Semver::satisfies((string) config('saft.version'), $saft));
        } catch (Throwable) {
            return null;
        }
    }
}
