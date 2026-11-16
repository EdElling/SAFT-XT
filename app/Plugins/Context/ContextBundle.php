<?php

declare(strict_types=1);

namespace App\Plugins\Context;

use App\Plugins\Api\ApiManifest;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\RecordListener;
use App\Plugins\PluginInspector;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use App\Support\AppLocale;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Entwickler-Kontext für Plugins (Menschen und KI-Agenten): AGENTS.md, api-reference.md, schema.md und schema.json
 * (Englisch). Enthält keine Datensätze, Benutzer oder Zugangsdaten. Konsole: saft:plugin-context; Oberfläche:
 * Administration › Plugins › „Entwickler-Kontext herunterladen“.
 */
final readonly class ContextBundle
{
    public function __construct(
        private PluginRegistry $plugins,
        private PluginInspector $inspector,
        private SchemaExporter $schema,
        private SchemaMarkdown $schemaMarkdown,
        private ApiReference $reference,
    ) {}

    /**
     * @param  list<string>|null  $tables  technische Namen (null = alle)
     * @return array<string, string> Dateiname => Inhalt
     */
    public function files(?array $tables = null, bool $external = true): array
    {
        $schema = $this->schema->export($tables, $external);

        return [
            'AGENTS.md' => $this->agents($schema),
            'api-reference.md' => $this->reference->markdown(),
            'schema.md' => $this->schemaMarkdown->markdown($schema),
            'schema.json' => json_encode(self::compact($schema), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        ];
    }

    /**
     * @param  list<string>|null  $tables
     * @return list<string> geschriebene Dateien
     */
    public function write(string $directory, ?array $tables = null, bool $external = true): array
    {
        File::ensureDirectoryExists($directory);
        $written = [];

        foreach ($this->files($tables, $external) as $name => $content) {
            File::put($directory.'/'.$name, $content);
            $written[] = $directory.'/'.$name;
        }

        return $written;
    }

    /** @param  list<string>|null  $tables */
    public function zip(string $path, ?array $tables = null, bool $external = true): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("ZIP-Datei {$path} lässt sich nicht anlegen.");
        }

        foreach ($this->files($tables, $external) as $name => $content) {
            $zip->addFromString('saft-plugin-context/'.$name, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * Leere Angaben (null, leere Listen) weglassen – der Table-Designer setzt dafür seine Vorgaben.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function compact(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = self::compact($item);
            }

            if ($item !== null && ($item !== [] || is_int($key))) {
                $result[$key] = $item;
            }
        }

        return array_is_list($value) ? array_values($result) : $result;
    }

    /** @param  array<string, mixed>  $schema */
    private function agents(array $schema): string
    {
        $version = static fn (string $package): string => InstalledVersions::isInstalled($package) ? ltrim((string) InstalledVersions::getPrettyVersion($package), 'v') : '?';

        $environment = implode("\n", [
            '- SAFT '.config('saft.version').', plugin API '.ApiManifest::current().' (state `"api": "^'.ApiManifest::current().'"` in `extra.saft` of the plugin\'s composer.json)',
            '- PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.', Laravel '.$version('laravel/framework').', Filament '.$version('filament/filament').', Livewire '.$version('livewire/livewire'),
            '- User interface languages: '.implode(', ', AppLocale::available()).' (default '.config('locales.default').')',
            '- Databases: PostgreSQL, MySQL, MariaDB – code must run on all of them',
        ]);

        $tables = array_map(static fn (array $t): string => '`'.$t['name'].'`'.(! empty($t['external']) ? ' (external, read-only)' : ''), $schema['tables']);
        $installation = [
            '**Business tables** ('.count($tables).', see `schema.md`): '.($tables === [] ? '–' : implode(', ', $tables)).'.',
            '',
            '**Installed plugins:**',
            '',
            ...($this->plugins->all() === [] ? ['- none'] : array_map($this->pluginLine(...), array_values($this->plugins->all()))),
            '',
            '**Formula functions from plugins** (computed fields only, not in report expressions): '
                .(($f = array_keys($this->plugins->formulaFunctions())) === [] ? '–' : implode(', ', array_map(static fn (string $n): string => '`'.$n.'()`', $f))).'.',
            '',
            '**Validation rules from plugins** (`validation_rules.checks` of a field): '
                .(($r = array_keys($this->plugins->fieldRules())) === [] ? '–' : implode(', ', array_map(static fn (string $n): string => '`'.$n.'`', $r))).'.',
        ];

        return strtr((string) file_get_contents(base_path('stubs/plugin-context/AGENTS.md.stub')), [
            '{{ environment }}' => $environment,
            '{{ installation }}' => implode("\n", $installation),
        ]);
    }

    private function pluginLine(SaftPlugin $plugin): string
    {
        $parts = [];

        if ($plugin->requiredTables() !== []) {
            $parts[] = 'requires '.implode(', ', array_map(static fn (string $t): string => '`'.$t.'`', array_keys($plugin->requiredTables())));
        }

        if (($listens = array_unique(array_map(static fn (RecordListener $l): string => $l->table, $plugin->recordListeners()))) !== []) {
            $parts[] = 'listens to '.implode(', ', array_map(static fn (string $t): string => '`'.$t.'`', $listens));
        }

        if (($actions = array_unique(array_map(static fn (DataAction $a): string => $a->table, $plugin->dataActions()))) !== []) {
            $parts[] = 'buttons in '.implode(', ', array_map(static fn (string $t): string => '`'.$t.'`', $actions));
        }

        if (($widgets = $this->inspector->widgets($plugin)) !== []) {
            $parts[] = count($widgets).' dashboard widget(s)';
        }

        return '- `'.$plugin->id().'` '.$plugin->version().' ('.$plugin->packageName().')'.($parts === [] ? '' : ': '.implode('; ', $parts));
    }
}
