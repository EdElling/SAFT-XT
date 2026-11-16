<?php

declare(strict_types=1);

namespace App\Plugins\Api;

/**
 * Widget eines Plugins auf dem Dashboard (Klasse unter src/Widgets). Die Bezeichnung erscheint beim Anpassen des
 * Dashboards (ausgeblendete Karten); wer die Karte sieht, regelt canView() des Widgets.
 *
 * @api
 */
interface DashboardWidget
{
    /** Bezeichnung der Karte in der aktuellen Sprache, z. B. __('mein-plugin::widgets.offen'). */
    public static function dashboardLabel(): string;
}
