<?php

declare(strict_types=1);

namespace Saft\ChPruefungen;

use App\Plugins\PluginServiceProvider;

/** Meldet das Plugin an; Übersetzungen unter __('ch-pruefungen::pruefungen.…'). */
final class ChPruefungenServiceProvider extends PluginServiceProvider
{
    protected function plugin(): string
    {
        return ChPruefungenPlugin::class;
    }
}
