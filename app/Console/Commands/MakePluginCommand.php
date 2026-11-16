<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Plugins\Api\ApiManifest;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** Erzeugt das Gerüst eines Plugins unter plugins/<id> aus den Vorlagen in stubs/plugin. */
#[Signature('saft:make-plugin {id : Kennung, z. B. kunden-portal} {--label= : Bezeichnung} {--vendor=saft : Hersteller im Paketnamen} {--namespace= : PHP-Namensraum, z. B. Acme\\KundenPortal}')]
#[Description('Erzeugt das Gerüst eines neuen Plugins unter plugins/')]
class MakePluginCommand extends Command
{
    private const string NAMESPACE_PATTERN = '/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)+$/';

    private const string VENDOR_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*$/';

    public function handle(Filesystem $files, PluginRegistry $plugins): int
    {
        $id = (string) $this->argument('id');
        $vendor = (string) $this->option('vendor');
        $label = trim((string) ($this->option('label') ?? '')) ?: Str::headline($id);
        $class = Str::studly($id);
        $namespace = trim((string) ($this->option('namespace') ?? ''), '\\') ?: Str::studly($vendor).'\\'.$class;
        $target = base_path('plugins/'.$id);

        $error = match (true) {
            ! preg_match(SaftPlugin::ID_PATTERN, $id) => __('plugins.make.invalid_id', ['id' => $id]),
            ! preg_match(self::VENDOR_PATTERN, $vendor) => __('plugins.make.invalid_vendor', ['vendor' => $vendor]),
            ! preg_match(self::NAMESPACE_PATTERN, $namespace) => __('plugins.make.invalid_namespace', ['namespace' => $namespace]),
            strpbrk($label, '\'"\\{}') !== false => __('plugins.make.invalid_label'),
            $plugins->has($id) => __('plugins.make.installed', ['id' => $id]),
            $files->exists($target) => __('plugins.make.exists', ['folder' => 'plugins/'.$id]),
            default => null,
        };

        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $package = $vendor.'/'.$id;
        $replace = [
            '{{ id }}' => $id,
            '{{ label }}' => $label,
            '{{ class }}' => $class,
            '{{ namespace }}' => $namespace,
            '{{ namespace_json }}' => str_replace('\\', '\\\\', $namespace),
            '{{ package }}' => $package,
            '{{ api }}' => '^'.ApiManifest::current(),
        ];

        $map = [
            'composer.json.stub' => 'composer.json',
            'README.md.stub' => 'README.md',
            'AGENTS.md.stub' => 'AGENTS.md',
            'gitignore.stub' => '.gitignore',
            'Plugin.php.stub' => "src/{$class}Plugin.php",
            'ServiceProvider.php.stub' => "src/{$class}ServiceProvider.php",
            'Pages/StartPage.php.stub' => 'src/Pages/StartPage.php',
            'lang/de/plugin.php.stub' => 'lang/de/plugin.php',
            'lang/en/plugin.php.stub' => 'lang/en/plugin.php',
            'tests/PluginTest.php.stub' => "tests/{$class}Test.php",
        ];

        foreach ($map as $stub => $file) {
            $files->ensureDirectoryExists(dirname($target.'/'.$file));
            $files->put($target.'/'.$file, strtr($files->get(base_path('stubs/plugin/'.$stub)), $replace));
        }

        $this->info(__('plugins.make.created', ['folder' => 'plugins/'.$id, 'package' => $package]));
        $this->newLine();
        $this->line(__('plugins.make.next'));

        foreach ([
            "composer require {$package}",
            'php artisan saft:plugins',
            "php artisan saft:plugins --check {$id}",
            "vendor/bin/phpunit --testsuite Plugins --filter {$class}Test",
            "php artisan saft:plugin-context --output=plugins/{$id}/context",
            "cd plugins/{$id} && git init",
        ] as $step) {
            $this->line('  '.$step);
        }

        return self::SUCCESS;
    }
}
