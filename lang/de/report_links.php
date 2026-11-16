<?php

declare(strict_types=1);

return [
    'model' => 'Tabellenverknüpfung',
    'plural' => 'Tabellenverknüpfungen',
    'description' => 'Verknüpfen Tabellen ohne Fremdschlüssel – auch SAFT-Tabellen mit angebundenen Tabellen oder Tabellen verschiedener Verbindungen. Wie ein Verknüpfungsfeld: viele Zeilen der Quelle zeigen auf eine Zeile des Ziels. Nutzbar in Auswertungen und als Details in der Master-Detail-Ansicht. In der Quelldatenbank entsteht nichts.',
    'label' => 'Bezeichnung',
    'label_help' => 'Erscheint im Feldbaum des Auswertungs-Editors und als Überschrift der Details, z. B. „Kunde (ERP)“.',
    'source' => 'Quelle',
    'target' => 'Ziel',
    'table' => 'Tabelle',
    'source_field' => 'Feld der Quelle',
    'source_field_help' => 'Ganzzahl-, Text- oder Auswahlfeld mit dem Wert, der im Ziel gesucht wird.',
    'target_field' => 'Eindeutiges Feld des Ziels',
    'target_field_help' => 'SAFT-Tabelle: id oder ein Feld mit „eindeutig“; angebundene Tabelle: ihr Schlüssel. Nur zum Quellfeld passende Felder (Ganzzahl bzw. Text).',
    'coverage_label' => 'Treffer',
    'coverage' => ':matched von :total Zeilen mit Wert finden ein Ziel (:percent %).',
    'coverage_no_snapshot' => 'Keine Aussage: Eine angebundene Tabelle hat noch keinen Datenstand (oder das Feld fehlt darin).',
    'external' => 'angebunden, :connection',
    'designer' => 'SAFT-Tabelle',
    'errors' => [
        'source_field' => 'Das Feld gehört nicht zur Quelltabelle oder taugt nicht als Verknüpfung (nur Ganzzahl, Text, Auswahl).',
        'target_unique' => 'Dieses Feld von „:table“ ist nicht eindeutig. Ziel kann nur id, ein Feld mit „eindeutig“ oder der Schlüssel einer angebundenen Tabelle sein.',
        'kind_mismatch' => 'Quell- und Zielfeld passen nicht zusammen: Ganzzahl nur mit Ganzzahl, Text nur mit Text.',
        'same_field' => 'Quelle und Ziel sind dasselbe Feld.',
        'duplicate' => 'Für dieses Feld gibt es schon eine Verknüpfung zu dieser Tabelle.',
    ],
];
