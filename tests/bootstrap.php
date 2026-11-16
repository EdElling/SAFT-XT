<?php

declare(strict_types=1);

/*
 * Bootstrap der PHPUnit-Konfigurationen: Composer lädt „autoload-dev“ nur für SAFT selbst, nicht für Pakete. Damit die
 * Tests der Plugins (plugins/<name>/tests) laufen, werden die autoload-dev-Einträge der installierten Plugins hier
 * nachgetragen. Plugins, die nicht installiert sind, werden übersprungen (PluginTestCase überspringt ihre Tests).
 */

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;

/** @var ClassLoader $loader */
$loader = require __DIR__.'/../vendor/autoload.php';

foreach (glob(dirname(__DIR__).'/plugins/*/composer.json') ?: [] as $manifest) {
    $composer = json_decode((string) file_get_contents($manifest), true);

    if (! is_array($composer) || ! is_string($composer['name'] ?? null) || ! InstalledVersions::isInstalled($composer['name'])) {
        continue;
    }

    foreach ((array) ($composer['autoload-dev']['psr-4'] ?? []) as $namespace => $paths) {
        $loader->addPsr4((string) $namespace, array_map(
            static fn (string $path): string => dirname($manifest).'/'.$path,
            (array) $paths,
        ));
    }
}
