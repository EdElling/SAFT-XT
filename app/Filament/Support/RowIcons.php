<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Throwable;

/**
 * Zeilenaktionen der Admin-Tabellen nur als Symbol mit Tooltip (B-113, wie in den Datentabellen). Ein eigener Tooltip
 * der Aktion (z. B. Herkunft aus einem Plugin) bleibt hinter der Bezeichnung erhalten; Aktionen ohne Symbol behalten
 * ihre Beschriftung.
 */
final class RowIcons
{
    /**
     * @param  array<Action|ActionGroup>  $actions
     * @return array<Action|ActionGroup>
     */
    public static function of(array $actions): array
    {
        return array_map(static fn (Action|ActionGroup $action): Action|ActionGroup => $action instanceof Action ? self::icon($action) : $action, $actions);
    }

    public static function icon(Action $action): Action
    {
        try {
            // Standard-Aktionen (Bearbeiten, Löschen, Anzeigen …) haben ihr Symbol als Tabellen-Symbol.
            $icon = $action->getIcon(default: $action->getTableIcon());
        } catch (Throwable) {
            $icon = 'deferred'; // hängt vom Datensatz ab – es gibt eines
        }

        if (blank($icon)) {
            return $action;
        }

        // Ansehen/Anzeigen: Lupe (das Auge steht für Ein-/Ausblenden).
        if ($action instanceof ViewAction) {
            $action->icon('heroicon-o-magnifying-glass');
        }

        $own = (fn (): mixed => $this->tooltip)->call($action);

        return $action->iconButton()->tooltip(static function (Action $action) use ($own): string {
            $text = $own === null ? '' : trim((string) $action->evaluate($own));
            $label = (string) $action->getLabel();

            return $text !== '' && $text !== $label ? $label.' · '.$text : $label;
        });
    }
}
