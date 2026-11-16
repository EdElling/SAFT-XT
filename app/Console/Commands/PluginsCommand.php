<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Pages\PluginsPage;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginInspector;
use App\Plugins\PluginPermissions;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use App\Plugins\SaftPlugin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Installierte Plugins mit Version und Kompatibilität; legt fehlende Rechte an. Mit --check: Prüfung vor der Auslieferung. */
#[Signature('saft:plugins {id? : nur dieses Plugin prüfen (mit --check)} {--check : Plugins prüfen (Angaben, Kompatibilität, nur Plugin-API)} {--enable= : Plugin einschalten (Kennung)} {--disable= : Plugin ausschalten (Kennung)}')]
#[Description('Listet die installierten Plugins und legt ihre Rechte an; --check prüft sie, --enable/--disable schaltet eines ein oder aus')]
class PluginsCommand extends Command
{
    public function handle(PluginRegistry $plugins, PluginPermissions $permissions, PluginInspector $inspector, PluginStates $states): int
    {
        if ($this->option('check')) {
            return $this->check($plugins, $inspector);
        }

        foreach (['enable' => true, 'disable' => false] as $option => $enabled) {
            if (is_string($id = $this->option($option))) {
                return $this->switch($plugins, $states, $id, $enabled);
            }
        }

        if ($plugins->all() === []) {
            $this->info(__('plugins.empty'));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', __('plugins.fields.name'), __('plugins.fields.status'), __('plugins.fields.version'), __('plugins.fields.requires'), __('plugins.fields.compatibility')],
            array_map(static fn (SaftPlugin $plugin): array => [
                $plugin->id(),
                $plugin->label(),
                __('plugins.status.'.$states->status($plugin)),
                $plugin->version() ?: '–',
                PluginsPage::requirement($plugin) ?: '–',
                __('plugins.compatibility.'.match ($plugin->isCompatible()) {
                    true => 'yes', false => 'no', null => 'unknown'
                }),
            ], array_values($plugins->all())),
        );

        foreach ($permissions->sync() as $name) {
            $this->line(__('plugins.console.permission_created', ['name' => $name]));
        }

        return self::SUCCESS;
    }

    /** Ein- oder ausschalten; im Änderungsprotokoll ohne Person (Konsole). */
    private function switch(PluginRegistry $plugins, PluginStates $states, string $id, bool $enabled): int
    {
        if (! $plugins->has($id)) {
            $this->error(__('plugins.check.unknown', ['id' => $id]));

            return self::FAILURE;
        }

        $plugin = $plugins->get($id);
        $enabled ? $states->enable($id) : $states->disable($id);
        $this->info(__($enabled ? 'plugins.switch.enabled' : 'plugins.switch.disabled', ['plugin' => $plugin->label()]));

        if (! $enabled && ($dependents = $states->dependents($plugin)) !== []) {
            $this->warn(__('plugins.switch.dependents_resting', ['plugins' => app(PluginDependencies::class)->names($dependents)]));
        }

        return self::SUCCESS;
    }

    private function check(PluginRegistry $plugins, PluginInspector $inspector): int
    {
        $id = $this->argument('id');
        $uninstalled = $inspector->uninstalled();

        if (is_string($id) && ! $plugins->has($id)) {
            $folder = 'plugins/'.$id;
            $this->error(isset($uninstalled[$folder])
                ? __('plugins.check.not_installed', ['folder' => $folder, 'package' => $uninstalled[$folder]])
                : __('plugins.check.unknown', ['id' => $id]));

            return self::FAILURE;
        }

        $selected = is_string($id) ? [$plugins->get($id)] : array_values($plugins->all());
        $errors = 0;

        foreach ($selected as $plugin) {
            $this->newLine();
            $this->line('<options=bold>'.$plugin->label().'</> ('.$plugin->id().', '.$plugin->location().')');

            foreach ($inspector->inspect($plugin) as $result) {
                $errors += $result['level'] === PluginInspector::ERROR ? 1 : 0;
                $this->line(match ($result['level']) {
                    PluginInspector::OK => '  <fg=green>✔</> ',
                    PluginInspector::WARNING => '  <fg=yellow>!</> ',
                    default => '  <fg=red>✘</> ',
                }.$result['message']);
            }
        }

        if ($id === null) {
            foreach ($uninstalled as $folder => $package) {
                $this->newLine();
                $this->warn(__('plugins.check.not_installed', ['folder' => $folder, 'package' => $package]));
            }

            if (($issues = $inspector->tableDesignerIssues()) !== []) {
                $this->newLine();
                $this->line('<options=bold>'.__('plugins.check.designer_heading').'</>');

                foreach ($issues as $issue) {
                    $this->line('  <fg=yellow>!</> '.$issue);
                }
            }

            if ($selected === [] && $uninstalled === []) {
                $this->info(__('plugins.empty'));
            }
        }

        $this->newLine();
        $errors === 0
            ? $this->info(__('plugins.check.passed'))
            : $this->error(trans_choice('plugins.check.failed', $errors, ['count' => $errors]));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
