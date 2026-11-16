<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CustomTable;
use App\Plugins\Context\ContextBundle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Schreibt den Entwickler-Kontext für Plugins (AGENTS.md, API-Referenz, Schema) in einen Ordner oder eine ZIP-Datei. */
#[Signature('saft:plugin-context {--output= : Zielordner (Standard: storage/app/plugin-context) bzw. ZIP-Datei mit --zip} {--tables=* : nur diese Tabellen (technische Namen)} {--without-external : angebundene Tabellen weglassen} {--zip : als ZIP-Datei}')]
#[Description('Erzeugt den Entwickler-Kontext für Plugins (auch für KI-Agenten)')]
class PluginContextCommand extends Command
{
    public function handle(ContextBundle $bundle): int
    {
        $tables = array_values(array_filter((array) $this->option('tables'))) ?: null;

        if ($tables !== null && ($unknown = array_diff($tables, CustomTable::query()->pluck('name')->all())) !== []) {
            $this->error(__('plugins.context.unknown_tables', ['tables' => implode(', ', $unknown)]));

            return self::FAILURE;
        }

        $external = ! $this->option('without-external');
        $output = (string) ($this->option('output') ?: storage_path('app/plugin-context'.($this->option('zip') ? '.zip' : '')));

        $files = $this->option('zip')
            ? [$bundle->zip($output, $tables, $external)]
            : $bundle->write($output, $tables, $external);

        foreach ($files as $file) {
            $this->line(__('plugins.context.written', ['file' => $file]));
        }

        return self::SUCCESS;
    }
}
