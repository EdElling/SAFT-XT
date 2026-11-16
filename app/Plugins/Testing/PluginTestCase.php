<?php

declare(strict_types=1);

namespace App\Plugins\Testing;

use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\Api\DataTable;
use App\Plugins\Api\Saft;
use App\Plugins\Context\SchemaImporter;
use App\Services\DynamicSchemaService;
use Closure;
use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionClass;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * Basis für die Tests eines Plugins (Ordner tests/ des Plugins, Testsuite „Plugins“). Jeder Test beginnt mit einer
 * leeren Testdatenbank samt Rollen, Rechten und Menü; Tabellen des Table-Designers legt createTable() an. Tests eines
 * Plugins, das nicht installiert ist, werden übersprungen.
 *
 * Nur in Entwicklungsinstallationen verfügbar (benötigt die Dev-Pakete von SAFT).
 *
 * @api
 */
abstract class PluginTestCase extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    /** Auch die Tabellen des Table-Designers in der Test-Transaktion (PostgreSQL: DDL wird mit zurückgerollt). */
    protected array $connectionsToTransact = [null, 'app_data'];

    protected function setUp(): void
    {
        $package = $this->pluginPackage();

        if ($package !== null && ! InstalledVersions::isInstalled($package)) {
            $this->markTestSkipped("Plugin {$package} ist nicht installiert (composer require {$package}).");
        }

        parent::setUp();
        $this->seedAdminStructure();
    }

    /** Meldet eine Super-Admin-Person an (alle Rechte). */
    protected function actingAsSuperAdmin(): User
    {
        $this->actingAs($user = $this->superAdmin());

        return $user;
    }

    /**
     * Meldet eine Person mit genau diesen Rechten an, z. B. ['mein-plugin.use', 'data.kunden.view'].
     *
     * @param  list<string>  $permissions
     */
    protected function actingWithPermissions(array $permissions): User
    {
        $this->actingAs($user = $this->userWithPermissions($permissions));

        return $user;
    }

    /**
     * Legt eine Tabelle des Table-Designers an, wie die Administration es täte.
     *
     * @param  list<array<string, mixed>>  $fields  je Feld name, label, type und optional options, is_required,
     *                                              validation_rules (wie im Table-Designer)
     */
    protected function createTable(string $name, string $label, array $fields): DataTable
    {
        app(DynamicSchemaService::class)->createTable(['name' => $name, 'label' => $label], $fields);

        return Saft::table($name);
    }

    /**
     * Legt die Tabellen aus einer schema.json des Entwickler-Kontexts an (Administration › Plugins › „Entwickler-Kontext
     * herunterladen“ oder php artisan saft:plugin-context) – so lässt sich gegen das Schema einer anderen Installation
     * testen. Angebundene Tabellen werden übergangen.
     *
     * @param  list<string>  $only  nur diese Tabellen (leer = alle); Verknüpfungsziele müssen dabei sein
     * @return array<string, DataTable> angelegte Tabellen nach Name
     */
    protected function createTablesFromSchema(string $path, array $only = []): array
    {
        $schema = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_map(
            static fn (CustomTable $table): DataTable => Saft::table($table->name),
            app(SchemaImporter::class)->import($schema, $only),
        );
    }

    /**
     * Führt $callback aus, ohne dass Listener von Plugins reagieren – für Testdaten, die die eigenen Regeln umgehen.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withoutPluginEvents(Closure $callback): mixed
    {
        return DynamicRecord::withoutPluginEvents($callback);
    }

    /** Datenansicht einer Tabelle als Livewire-Test, z. B. für die Aktionen des Plugins (TestAction::make(…)->table($record)). */
    protected function dataView(string $table): Testable
    {
        return Livewire::test(DynamicDataPage::class, ['table' => $table]);
    }

    /** Paketname des Plugins, zu dem dieser Test gehört (Ordner plugins/<name>/), sonst null. */
    private function pluginPackage(): ?string
    {
        $file = realpath((string) (new ReflectionClass($this))->getFileName()) ?: '';
        // Vor parent::setUp() gibt es noch keine Anwendung (kein base_path()).
        $plugins = (realpath(dirname(__DIR__, 3).'/plugins') ?: dirname(__DIR__, 3).'/plugins').'/';

        if (! str_starts_with($file, $plugins)) {
            return null;
        }

        $manifest = $plugins.strtok(substr($file, strlen($plugins)), '/').'/composer.json';
        $composer = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

        return is_array($composer) && is_string($composer['name'] ?? null) ? $composer['name'] : null;
    }
}
