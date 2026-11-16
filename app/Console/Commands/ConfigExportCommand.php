<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Configuration\ConfigExporter;
use App\Configuration\References;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\Role;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Konfigurationspaket schreiben (Tabellen, Auswertungen, Menüs, Rollen) – z. B. für ein Deployment von Test nach Produktion. */
#[Signature('saft:config-export {output : Zieldatei (.json)} {--tables=* : Tabellen (technische Namen)} {--reports=* : Auswertungen (Namen)} {--menus=* : Menüeinträge (Pfad, z. B. "Daten › Verkauf")} {--roles=* : Rollen (Namen)} {--all : alles Exportierbare} {--without-dependencies : Abhängigkeiten nicht mitnehmen} {--label= : Bezeichnung des Pakets}')]
#[Description('Exportiert Konfiguration (Tabellen, Auswertungen, Menüs, Rollen) als Paket')]
class ConfigExportCommand extends Command
{
    public function handle(ConfigExporter $exporter): int
    {
        $all = (bool) $this->option('all');
        $menus = Menu::query()->get();
        $paths = $menus->mapWithKeys(fn (Menu $m): array => [(int) $m->id => app(References::class)->menuPath((int) $m->id)]);

        $package = $exporter->export(
            tables: $all ? CustomTable::query()->designer()->pluck('name')->all() : array_values((array) $this->option('tables')),
            // Auf der Konsole gibt es keine angemeldete Person: nur freigegebene Auswertungen.
            reports: Report::query()->where('is_shared', true)->when(! $all, fn ($q) => $q->whereIn('name', (array) $this->option('reports')))->pluck('id')->all(),
            menus: $all ? $menus->pluck('id')->all() : $paths->filter(fn (?string $p): bool => in_array($p, (array) $this->option('menus'), true))->keys()->all(),
            roles: Role::query()->when(! $all, fn ($q) => $q->whereIn('name', (array) $this->option('roles')))->pluck('id')->all(),
            dependencies: ! $this->option('without-dependencies'),
            label: (string) $this->option('label'),
        );

        File::put((string) $this->argument('output'), (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        activity('configuration')->event('config_exported')->withChanges(['attributes' => ['paket' => $package['label'], 'konsole' => true]])->log('config_exported');
        $this->info(__('configuration.console.written', ['file' => $this->argument('output')]));

        return self::SUCCESS;
    }
}
