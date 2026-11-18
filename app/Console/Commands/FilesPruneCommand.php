<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DynamicData\Files\FilePruner;
use App\Models\RecordFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Verwaiste Dateien aus Dateifeldern aufräumen (siehe FilePruner). */
#[Signature('files:prune {--hours=24 : Hochgeladenes und Abgelegtes erst nach so vielen Stunden löschen} {--dry-run : Nur zählen, nichts löschen}')]
#[Description('Räumt verwaiste Dateien aus Dateifeldern auf')]
class FilesPruneCommand extends Command
{
    public function handle(FilePruner $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $pruner->prune(max(1, (int) $this->option('hours')), $dryRun);
        $size = RecordFile::sizeText($result['bytes']);

        $this->info(($dryRun ? 'Würde löschen: ' : 'Gelöscht: ').$result['deleted'].' Dateien ('.$size.').');

        foreach ($result['missing'] as $path) {
            $this->warn('Datei fehlt im Speicher: '.$path);
        }

        return self::SUCCESS;
    }
}
