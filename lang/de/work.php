<?php

declare(strict_types=1);

return [
    'model' => 'Arbeit',
    'plural' => 'Arbeiten',
    'status' => [
        'queued' => 'wartet',
        'running' => 'läuft',
        'done' => 'fertig',
        'failed' => 'fehlgeschlagen',
        'cancelled' => 'abgebrochen',
    ],
    'types' => [
        'recompute' => 'Berechnete Felder neu berechnen',
    ],
    'recompute' => [
        'all_tables' => 'alle Tabellen mit berechneten Feldern',
    ],
    'cancelled' => 'Abgebrochen.',
    'aborted_repeatedly' => 'Mehrmals ohne Abschluss abgebrochen (z. B. Zeitlimit des Servers überschritten). Die Arbeit wurde beendet.',
    'failed_generic' => 'Die Arbeit ist fehlgeschlagen (Referenz :reference). Details stehen im Systemprotokoll.',
    'plugin_inactive' => 'Das Plugin „:plugin“ ruht; die Arbeit wurde nicht ausgeführt.',
    'started' => 'Arbeit gestartet (:id); der Scheduler führt sie im Hintergrund aus.',
];
