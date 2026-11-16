<?php

declare(strict_types=1);

return [
    'title' => 'Datenmodell: :group',
    'title_all' => 'Datenmodell',
    'show_fields' => 'Felder anzeigen',
    'empty' => 'In dieser Menügruppe gibt es keine Datentabellen, die Sie sehen dürfen.',
    'load_failed' => 'Die Diagramm-Bibliothek konnte nicht geladen werden.',
    'render_failed' => 'Das Diagramm konnte nicht gezeichnet werden.',
    'legend' => [
        'solid' => 'Durchgezogene Linie: als Detailtabelle eingerichtet',
        'dashed' => 'Gestrichelte Linie: Verknüpfungsfeld',
        'link' => '⇄ vor der Beschriftung: Tabellenverknüpfung (ohne Fremdschlüssel, siehe Administration)',
        'cardinality' => 'Krähenfuss: viele Datensätze; || genau einer (Pflichtfeld), o| keiner oder einer',
        'outside' => 'Gestrichelter Rahmen: Tabelle ausserhalb dieser Gruppe',
        'external' => 'Orangefarbener Rahmen: angebundene Tabelle (Verbindung in Klammern)',
        'fields' => 'PK Schlüssel, FK Verknüpfung, UK eindeutig, * Pflichtfeld',
    ],
];
