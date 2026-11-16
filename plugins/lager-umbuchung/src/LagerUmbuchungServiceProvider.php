<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung;

use App\Plugins\PluginServiceProvider;

/** Meldet das Plugin an; Übersetzungen unter __('lager-umbuchung::umbuchung.…'). */
final class LagerUmbuchungServiceProvider extends PluginServiceProvider
{
    protected function plugin(): string
    {
        return LagerUmbuchungPlugin::class;
    }
}
