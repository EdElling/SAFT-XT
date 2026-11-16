<?php

declare(strict_types=1);

return [
    'plugin' => 'Lager-Umbuchung',
    'description' => 'Beispiel-Plugin: Ware zwischen Lagerorten umbuchen; Lagerbewegungen buchen den Bestand; Meldung bei Unterschreiten des Mindestbestands (Lager-Demo).',
    'permission' => 'Umbuchungen ausführen',
    'title' => 'Umbuchung',
    'help' => 'Bucht Ware von einem Lagerort an einen anderen um. Die Bestände an beiden Orten werden nachgeführt, und es entstehen zwei Lagerbewegungen der Art „Umbuchung“ (−/+).',
    'fields' => [
        'article' => 'Artikel',
        'from' => 'Von Lagerort',
        'to' => 'Nach Lagerort',
        'quantity' => 'Menge',
        'note' => 'Bemerkung',
    ],
    'submit' => 'Umbuchen',
    'available' => 'Verfügbar am Quellort: :count',
    'location_with_stock' => ':location (Bestand: :count)',
    'no_stock_here' => 'Kein Bestand an diesem Lagerort',
    'no_stock' => 'Dieser Artikel hat an keinem Lagerort Bestand.',
    'unavailable_title' => 'Lager-Demo nicht vollständig',
    'unavailable' => 'Diese Seite braucht Tabellen und Felder der Lager-Demo. Es fehlt:',
    'done' => ':count × :article von :from nach :to umgebucht.',
    'minimum' => [
        'title' => 'Mindestbestand unterschritten',
        'body' => 'Gesamtbestand von „:article“: :count (Mindestbestand :minimum).',
    ],
    'errors' => [
        'same_location' => 'Quell- und Ziel-Lagerort müssen verschieden sein.',
        'quantity' => 'Die Menge muss mindestens 1 sein.',
        'not_enough' => 'Nicht genug Bestand am Quellort (verfügbar: :available).',
        'invalid' => 'Die Umbuchung verletzt Regeln der Tabellen:',
        'negative' => 'Nicht genug Bestand: „:article“ am Lagerort :location hat :available; die Bewegung würde ihn negativ machen.',
        'stock_locked' => 'Bestände werden über Lagerbewegungen geführt. Korrekturen bitte als Lagerbewegung der Art „Inventur“ erfassen.',
        'rights' => 'Ihnen fehlen Datenrechte für: :tables',
    ],
    'widget' => [
        'label' => 'Lager: Mindestbestand',
        'below' => 'Artikel unter Mindestbestand',
        'none' => 'Alle Artikel über dem Mindestbestand',
        'item' => ':article (:count/:minimum)',
        'more' => ':names und :count weitere',
        'total' => 'Gesamtbestand (Stück)',
        'transfer' => 'Zur Umbuchung',
    ],
];
