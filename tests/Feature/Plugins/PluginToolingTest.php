<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Plugins\Api\ApiManifest;
use App\Plugins\PluginInspector;
use App\Plugins\PluginRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Werkzeuge für Plugin-Entwicklung: Gerüst (saft:make-plugin) und Prüfung (saft:plugins --check). */
class PluginToolingTest extends DynamicDataTestCase
{
    private ?string $created = null;

    protected function tearDown(): void
    {
        if ($this->created !== null) {
            File::deleteDirectory($this->created);
        }

        parent::tearDown();
    }

    public function test_scaffold_creates_a_valid_plugin(): void
    {
        $id = 'probe-'.Str::lower(Str::random(6));
        $this->created = base_path('plugins/'.$id);

        $this->artisan('saft:make-plugin', ['id' => $id, '--label' => 'Kunden Portal', '--namespace' => 'Acme\\KundenPortal'])
            ->expectsOutputToContain("composer require saft/{$id}")
            ->assertSuccessful();

        $class = Str::studly($id);
        $composer = json_decode((string) file_get_contents($this->created.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame("saft/{$id}", $composer['name']);
        $this->assertSame('^'.ApiManifest::current(), $composer['extra']['saft']['api']);
        $this->assertSame(['Acme\\KundenPortal\\' => 'src/'], $composer['autoload']['psr-4']);
        $this->assertSame(['Acme\\KundenPortal\\Tests\\' => 'tests/'], $composer['autoload-dev']['psr-4']);
        $this->assertSame(["Acme\\KundenPortal\\{$class}ServiceProvider"], $composer['extra']['laravel']['providers']);

        foreach (["src/{$class}Plugin.php", "src/{$class}ServiceProvider.php", 'src/Pages/StartPage.php', 'lang/de/plugin.php', 'lang/en/plugin.php', "tests/{$class}Test.php"] as $file) {
            $code = (string) file_get_contents($this->created.'/'.$file);
            token_get_all($code, TOKEN_PARSE); // wirft ParseError bei Syntaxfehlern
            $this->assertStringNotContainsString('{{', $code, $file);
        }

        $this->assertStringContainsString("'{$id}'", (string) file_get_contents($this->created."/src/{$class}Plugin.php"));
        $agents = (string) file_get_contents($this->created.'/AGENTS.md');
        $this->assertStringNotContainsString('{{', $agents);
        $this->assertStringContainsString("--output=plugins/{$id}/context", $agents);
        $this->assertStringContainsString('/context/', (string) file_get_contents($this->created.'/.gitignore'));
        $this->assertSame([], app(PluginInspector::class)->apiViolations($this->created.'/src'), 'Gerüst verwendet nur die Plugin-API.');
        $this->assertSame("saft/{$id}", app(PluginInspector::class)->uninstalled()["plugins/{$id}"] ?? null);

        $this->artisan('saft:make-plugin', ['id' => $id])->assertFailed();
    }

    public function test_scaffold_rejects_invalid_input(): void
    {
        foreach ([
            ['id' => 'Kunden_Portal'],
            ['id' => 'lager-umbuchung'],
            ['id' => 'probe-ok', '--namespace' => 'acme'],
            ['id' => 'probe-ok', '--vendor' => 'Acme Corp'],
            ['id' => 'probe-ok', '--label' => "O'Reilly"],
        ] as $arguments) {
            $this->artisan('saft:make-plugin', $arguments)->assertFailed();
        }

        $this->assertDirectoryDoesNotExist(base_path('plugins/probe-ok'));
    }

    public function test_inspector_reports_resting_plugins_without_errors(): void
    {
        $results = app(PluginInspector::class)->inspect(app(PluginRegistry::class)->get('lager-umbuchung'));
        $levels = array_column($results, 'level');

        $this->assertNotContains(PluginInspector::ERROR, $levels);
        $this->assertContains(PluginInspector::WARNING, $levels, 'Lager-Demo fehlt: Plugin ruht.');
        $this->assertContains(__('plugins.check.api_usage_ok'), array_column($results, 'message'));
    }

    public function test_inspector_finds_classes_outside_the_api(): void
    {
        $directory = storage_path('framework/testing/plugin-scan-'.Str::random(6));
        File::ensureDirectoryExists($directory.'/Pages');
        file_put_contents($directory.'/Pages/Probe.php', "<?php\nuse App\\Plugins\\Api\\Saft;\nuse App\\Models\\CustomTable;\n\$x = new \\App\\Services\\DynamicSchemaService();\n");

        try {
            $this->assertSame([
                'Pages/Probe.php: App\\Models\\CustomTable',
                'Pages/Probe.php: App\\Services\\DynamicSchemaService',
            ], app(PluginInspector::class)->apiViolations($directory));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_check_command_exit_codes(): void
    {
        $this->artisan('saft:plugins', ['--check' => true, 'id' => 'lager-umbuchung'])
            ->expectsOutputToContain(__('plugins.check.passed'))
            ->assertSuccessful();

        $this->artisan('saft:plugins', ['--check' => true, 'id' => 'gibt-es-nicht'])->assertFailed();

        config(['saft.api_versions' => ['2.0']]);
        $this->artisan('saft:plugins', ['--check' => true])
            ->expectsOutputToContain('Verlangt Plugin-API ^1.2')
            ->assertFailed();
    }
}
