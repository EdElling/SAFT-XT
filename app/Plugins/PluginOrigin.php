<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Filament\Pages\PluginsPage;

/**
 * Herkunft von Seiten, Kacheln, Knöpfen und Aufgaben aus Plugins sichtbar machen (B-063): Hinweis „Plugin: …“, Zusatz
 * zu Bezeichnungen, Tooltip. Zuordnung über den Namensraum der Klasse (PluginRegistry::ownerOf) – Plugins tun nichts.
 */
final readonly class PluginOrigin
{
    public function __construct(private PluginRegistry $plugins) {}

    public function of(object|string $class): ?SaftPlugin
    {
        return $this->plugins->ownerOf(is_object($class) ? $class::class : $class);
    }

    /** „Aus dem Plugin Lager-Tafel (Version 1.3.0)“ */
    public function tooltip(SaftPlugin $plugin): string
    {
        return $plugin->version() !== ''
            ? __('plugins.origin.tooltip_version', ['plugin' => $plugin->label(), 'version' => $plugin->version()])
            : __('plugins.origin.tooltip', ['plugin' => $plugin->label()]);
    }

    /** Bezeichnung mit Zusatz, z. B. „Lager: Inventur-Erinnerung (Plugin Lager-Tafel)“; ohne Plugin unverändert. */
    public function suffixed(string $label, object|string $class): string
    {
        $plugin = $this->of($class);

        return $plugin === null ? $label : __('plugins.origin.suffixed', ['label' => $label, 'plugin' => $plugin->label()]);
    }

    /** HTML-Hinweis für Seiten (unter dem Titel) und Kacheln (darunter); leer, wenn die Klasse aus keinem Plugin stammt. */
    public function badge(object|string $class, string $variant = 'page'): string
    {
        $plugin = $this->of($class);

        if ($plugin === null) {
            return '';
        }

        return view('filament.plugin-origin', [
            'plugin' => $plugin,
            'tooltip' => $this->tooltip($plugin),
            'url' => PluginsPage::canAccess() ? PluginsPage::getUrl() : null,
            'variant' => $variant,
        ])->render();
    }
}
