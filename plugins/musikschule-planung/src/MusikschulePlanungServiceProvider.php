<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Plugins\PluginServiceProvider;

/** Meldet das Plugin an; Übersetzungen unter __('musikschule-planung::planung.…'), Views unter view('musikschule-planung::…'). */
final class MusikschulePlanungServiceProvider extends PluginServiceProvider
{
    protected function plugin(): string
    {
        return MusikschulePlanungPlugin::class;
    }
}
