<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\DynamicData\Ui\DynamicRecordActions;
use Livewire\Attributes\On;

/**
 * Antwort auf „inzwischen geändert“ (optimistische Sperre, Fundament 2, F2) in Datenansicht und Detailtabelle: Die
 * Meldung bietet „Neu laden“ (Fenster schliessen, Tabelle neu laden) und „Trotzdem speichern“ (das offene Formular mit
 * der aktuellen Version erneut abschicken). Beides sind Livewire-Ereignisse mit der Kennung der Komponente, damit nur
 * die reagiert, die das Formular offen hat. Greift auf Filaments Zustand der gemounteten Aktion zu
 * (mountedActions.{n}.data) – deshalb an genau einer Stelle.
 */
trait HandlesStaleRecords
{
    #[On('stale-record-reload')]
    public function reloadAfterStaleRecord(string $component): void
    {
        if ($component !== $this->getId()) {
            return;
        }

        $this->unmountAction();
        $this->dispatch(DynamicRecordActions::REFRESH_EVENT);
    }

    #[On('stale-record-overwrite')]
    public function overwriteStaleRecord(string $component, string $version): void
    {
        if ($component !== $this->getId() || ($index = array_key_last($this->mountedActions ?? [])) === null) {
            return;
        }

        $this->mountedActions[$index]['data'][DynamicRecordActions::VERSION_FIELD] = $version;
        $this->mountedActions[$index]['data'][DynamicRecordActions::OVERWRITE_FIELD] = true;
        $this->callMountedAction();
    }
}
