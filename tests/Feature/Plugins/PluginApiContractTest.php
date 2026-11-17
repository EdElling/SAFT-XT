<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Plugins\Api\ApiManifest;
use App\Plugins\Context\ApiSurface;
use App\Plugins\PluginInspector;
use App\Plugins\PluginRegistry;
use Tests\TestCase;

/**
 * Vertrag der Plugin-Schnittstelle: öffentliche Methoden, Eigenschaften und Konstanten der zugesicherten Klassen
 * (ApiManifest) gegen die Momentaufnahme plugin-api.json.
 *
 * - Entfernt oder geändert → Breaking Change: nur mit neuer API-Major-Version (config/saft.php, api_versions).
 * - Neu hinzugekommen → API-Minor-Version erhöhen.
 * Danach die Momentaufnahme neu schreiben: SAFT_UPDATE_API_SNAPSHOT=1 vendor/bin/phpunit --filter PluginApiContractTest
 * und das Änderungsprotokoll im Entwicklerhandbuch ergänzen.
 */
class PluginApiContractTest extends TestCase
{
    private const string SNAPSHOT = __DIR__.'/plugin-api.json';

    public function test_api_surface_matches_the_snapshot_of_its_version(): void
    {
        $current = ['version' => ApiManifest::current(), 'surface' => ApiSurface::signatures()];

        if (getenv('SAFT_UPDATE_API_SNAPSHOT') === '1' || ! is_file(self::SNAPSHOT)) {
            file_put_contents(self::SNAPSHOT, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestSkipped('Momentaufnahme der Plugin-API geschrieben.');
        }

        $snapshot = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        $removed = [];
        $added = [];

        foreach ($snapshot['surface'] as $class => $members) {
            foreach (array_diff($members, $current['surface'][$class] ?? []) as $member) {
                $removed[] = "{$class}: {$member}";
            }
        }

        foreach ($current['surface'] as $class => $members) {
            foreach (array_diff($members, $snapshot['surface'][$class] ?? []) as $member) {
                $added[] = "{$class}: {$member}";
            }
        }

        $sameMajor = strtok((string) $snapshot['version'], '.') === strtok($current['version'], '.');

        $this->assertFalse($removed !== [] && $sameMajor, "Breaking Change an der Plugin-API (API {$snapshot['version']}). Entfernt oder geändert:\n- "
            .implode("\n- ", $removed)."\n\nNur mit neuer API-Major-Version zulässig (config/saft.php, api_versions), siehe Testkommentar.");
        $this->assertFalse($added !== [] && $snapshot['version'] === $current['version'], "Neue Teile der Plugin-API – API-Minor-Version erhöhen:\n- "
            .implode("\n- ", $added));
        $this->assertTrue($removed === [] && $added === [], 'Version erhöht – Momentaufnahme neu schreiben (SAFT_UPDATE_API_SNAPSHOT=1).');
    }

    public function test_plugins_only_use_the_plugin_api(): void
    {
        $violations = [];

        foreach (glob(base_path('plugins/*/src')) ?: [] as $directory) {
            foreach (app(PluginInspector::class)->apiViolations($directory) as $violation) {
                $violations[] = substr($directory, strlen(base_path()) + 1).'/'.$violation;
            }
        }

        $this->assertSame([], $violations, 'Plugins dürfen von SAFT nur die Plugin-API verwenden (ApiManifest).');
    }

    public function test_installed_plugins_are_compatible_with_the_provided_api(): void
    {
        foreach (app(PluginRegistry::class)->all() as $plugin) {
            $this->assertNotFalse($plugin->isCompatible(), $plugin->id().' passt nicht zur bereitgestellten API.');
        }

        config(['saft.api_versions' => [ApiManifest::current(), '2.0']]);
        $this->assertSame('2.0', ApiManifest::current());
        $this->assertTrue(app(PluginRegistry::class)->get('lager-umbuchung')->isCompatible(), 'API 1 weiterhin bereitgestellt.');

        config(['saft.api_versions' => ['1.0', '2.0']]);
        $this->assertFalse(app(PluginRegistry::class)->get('lager-umbuchung')->isCompatible(), 'Verlangt ^1.7 – API 1.0 genügt nicht.');

        config(['saft.api_versions' => ['2.0']]);
        $this->assertFalse(app(PluginRegistry::class)->get('lager-umbuchung')->isCompatible(), 'API 1 nicht mehr bereitgestellt.');
    }
}
