<?php

declare(strict_types=1);

namespace Saft\LagerTafel;

use App\Plugins\PluginServiceProvider;

/** Meldet das Plugin an; Übersetzungen unter __('lager-tafel::tafel.…'), Views unter view('lager-tafel::…'). */
final class LagerTafelServiceProvider extends PluginServiceProvider
{
    protected function plugin(): string
    {
        return LagerTafelPlugin::class;
    }
}
