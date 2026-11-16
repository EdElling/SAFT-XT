<?php

declare(strict_types=1);

return [
    'plugin' => 'Lager-Tafel',
    'description' => 'Beispiel-Plugin zu den Anleitungen im Entwicklerhandbuch: Lagerspiegel, Lagertafel mit Drag and Drop, Kachel, Listener und Prüfregel für Artikel (Lager-Demo, braucht Lager-Umbuchung).',
    'permissions' => [
        'view' => 'Lagerspiegel anzeigen',
        'transfer' => 'Auf der Lagertafel umbuchen',
    ],
    'unavailable_title' => 'Lager-Demo nicht vollständig',
    'unavailable' => 'Diese Seite braucht Tabellen und Felder der Lager-Demo. Es fehlt:',
    'no_rights' => 'Ihnen fehlt das Recht, Artikel, Lagerorte und Bestände anzuzeigen.',
    'actions' => [
        'matrix' => 'Lagerspiegel',
        'board' => 'Auf Lagertafel verteilen',
    ],
    'rules' => [
        'article_number' => 'Artikelnummer (Lager-Demo)',
        'article_number_invalid' => 'Artikelnummer im Format A-1234 erwartet (ein Buchstabe, Bindestrich, vier Ziffern).',
        'article_number_description' => 'Ein Grossbuchstabe, Bindestrich, vier Ziffern (A-1234). Kleinschreibung und Leerzeichen sind erlaubt; gespeichert wird die Nummer in Grossbuchstaben ohne Leerzeichen.',
    ],
    'errors' => [
        'number_locked' => 'Die Artikelnummer :number ist in Lagerbewegungen verwendet und kann nicht mehr geändert werden.',
    ],
    'matrix' => [
        'title' => 'Lagerspiegel',
        'number' => 'Nummer',
        'article' => 'Artikel',
        'total' => 'Gesamt',
        'minimum' => 'Mindestbestand',
        'below_minimum' => 'Nur unter Mindestbestand',
        'empty' => 'Keine Artikel',
    ],
    'board' => [
        'title' => 'Lagertafel',
        'article' => 'Artikel',
        'help' => 'Ziehen Sie den Bestand auf einen anderen Lagerort, um ihn umzubuchen.',
        'drag' => 'Auf einen anderen Lagerort ziehen',
        'empty' => 'Kein Bestand',
        'modal' => 'Umbuchen von :from nach :to',
        'submit' => 'Umbuchen',
        'quantity' => 'Menge',
        'rights' => 'Ihnen fehlen Datenrechte für: :tables',
        'invalid' => 'Die Umbuchung verletzt Regeln der Tabellen:',
        'done' => ':count Stück von :from nach :to umgebucht.',
    ],
    'tasks' => [
        'unavailable' => 'Lager-Demo nicht vollständig: :missing',
        'inventory' => [
            'label' => 'Lager: Inventur-Erinnerung',
            'days' => 'Nicht gezählt seit (Tagen)',
            'days_help' => 'Bestände mit Ware, deren letzte Zählung länger zurückliegt oder fehlt, werden gemeldet.',
            'describe' => 'Bestände ohne Zählung seit :days Tagen',
            'no_field' => 'Die Tabelle Bestände hat kein Feld „letzte_zaehlung“.',
            'item' => ':article (:location)',
            'none' => 'Alle Bestände wurden in den letzten :days Tagen gezählt.',
            'found' => '{1} 1 Bestand seit über :days Tagen nicht gezählt: :list|[2,*] :count Bestände seit über :days Tagen nicht gezählt: :list',
            'more' => ':list und :count weitere',
        ],
        'minimum' => [
            'label' => 'Lager: Mindestbestand melden',
            'describe' => 'Benachrichtigung an Personen mit dem Recht „Lagerspiegel anzeigen“',
            'no_field' => 'Die Tabelle Artikel hat kein Feld „mindestbestand“.',
            'none' => 'Alle Artikel liegen über dem Mindestbestand; niemand benachrichtigt.',
            'title' => '{1} 1 Artikel unter Mindestbestand|[2,*] :count Artikel unter Mindestbestand',
            'more' => ':list und :count weitere',
            'open' => 'Lagerspiegel öffnen',
            'done' => ':count Artikel unter Mindestbestand, :people Personen benachrichtigt.',
        ],
    ],
    'widget' => [
        'label' => 'Lager: Bestand je Lagerort',
        'description' => 'Summe aller Artikel',
        'quantity' => 'Bestand',
    ],
];
