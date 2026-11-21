<?php

declare(strict_types=1);

/** KI-App-Designer (B-107, Etappe 7a). */
return [
    'untitled' => 'Neuer Entwurf',
    'errors' => [
        'app_name' => 'Die App braucht einen Namen.',
        'prefix' => 'Das Präfix „:prefix“ ist ungültig: 1–20 Kleinbuchstaben und Ziffern, beginnend mit einem Buchstaben.',
        'prefix_taken' => 'Das Präfix „:prefix“ verwendet schon eine andere App.',
        'no_tables' => 'Der Entwurf hat keine Tabellen.',
        'table_prefix' => 'Die Tabelle „:table“ muss mit dem Präfix „:prefix“ beginnen.',
        'duplicate_table' => 'Die Tabelle „:table“ kommt doppelt vor.',
        'menu_label' => 'Eine Menügruppe hat keine Bezeichnung.',
        'menu_table' => 'Die Menügruppe nennt die Tabelle „:table“, die es im Entwurf nicht gibt.',
        'menu_twice' => 'Die Tabelle „:table“ steht in mehr als einer Menügruppe.',
        'invalid' => 'Kein gültiger Entwurf: :errors',
        'nothing_to_create' => 'Es gibt noch keinen Entwurf, aus dem sich eine App erzeugen lässt.',
        'already_created' => 'Aus diesem Entwurf wurde schon eine App erzeugt.',
    ],
];
