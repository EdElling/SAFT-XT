<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Configuration\ConfigImporter;
use App\Configuration\ImportItem;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Konfigurationspaket einspielen (nur Neues); mit --dry-run nur die Vorschau. */
#[Signature('saft:config-import {file : Paketdatei (.json)} {--dry-run : nur prüfen, nichts anlegen} {--app= : Ziel-App (ID); ohne Angabe die einzige vorhandene} {--new-app= : neue App mit diesem Namen anlegen und dorthin einspielen}')]
#[Description('Spielt ein Konfigurationspaket ein (legt nur Neues an)')]
class ConfigImportCommand extends Command
{
    public function handle(ConfigImporter $importer): int
    {
        try {
            $package = $importer->read((string) $this->argument('file'));
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($importer->missingRequirements($package) as $missing) {
            $this->warn('! '.$missing);
        }

        $items = $importer->analyze($package);
        $this->table(
            [__('configuration.columns.area'), __('configuration.columns.name'), __('configuration.columns.status'), __('configuration.columns.notes')],
            array_map(static fn (ImportItem $i): array => [__('configuration.areas.'.$i->area), $i->label, __('configuration.status.'.$i->status), implode(' · ', $i->notes)], $items),
        );

        $count = static fn (string $status): int => count(array_filter($items, static fn (ImportItem $i): bool => $i->status === $status));
        $this->line(__('configuration.console.summary', [
            'new' => $count(ImportItem::NEW), 'identical' => $count(ImportItem::IDENTICAL),
            'different' => $count(ImportItem::DIFFERENT), 'missing' => $count(ImportItem::MISSING),
        ]));

        if ($this->option('dry-run')) {
            $this->info(__('configuration.console.dry_run'));

            return self::SUCCESS;
        }

        if ($count(ImportItem::NEW) === 0) {
            $this->info(__('configuration.import.nothing'));

            return self::SUCCESS;
        }

        // Ziel-App (B-107): angegebene, neue oder – ohne Angabe – die einzige vorhandene.
        $app = filled($this->option('new-app'))
            ? App::query()->create(['name' => [app()->getLocale() => trim((string) $this->option('new-app'))]])
            : (filled($this->option('app')) ? (int) $this->option('app') : null);
        $created = $importer->apply($package, null, $app);
        $this->info(__('configuration.import.applied', [
            'tables' => $created[ImportItem::TABLE], 'links' => $created[ImportItem::REPORT_LINK], 'reports' => $created[ImportItem::REPORT],
            'menus' => $created[ImportItem::MENU], 'roles' => $created[ImportItem::ROLE],
        ]));

        return self::SUCCESS;
    }
}
