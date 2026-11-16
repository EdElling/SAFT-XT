<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Configuration\ConfigImporter;
use App\Configuration\ImportItem;
use App\Exceptions\BusinessRuleException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Konfigurationspaket einspielen (nur Neues); mit --dry-run nur die Vorschau. */
#[Signature('saft:config-import {file : Paketdatei (.json)} {--dry-run : nur prüfen, nichts anlegen}')]
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

        $created = $importer->apply($package, null);
        $this->info(__('configuration.import.applied', [
            'tables' => $created[ImportItem::TABLE], 'links' => $created[ImportItem::REPORT_LINK], 'reports' => $created[ImportItem::REPORT],
            'menus' => $created[ImportItem::MENU], 'roles' => $created[ImportItem::ROLE],
        ]));

        return self::SUCCESS;
    }
}
