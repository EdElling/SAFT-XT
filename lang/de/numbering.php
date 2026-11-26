<?php

declare(strict_types=1);

// Nummernkreise (B-135): Feldtyp „Nummernkreis“ im Table-Designer.
return [
    'fields' => [
        'pattern' => 'Muster',
        'reset' => 'Neubeginn',
        'date_field' => 'Bezugsdatum',
        'fiscal_start' => 'Geschäftsjahr beginnt im',
        'start' => 'Startwert',
    ],
    'form' => [
        'section' => 'Nummernkreis',
        'pattern_help' => 'Fester Text und Platzhalter, z. B. R-{YYYY}-{0000}. Die laufende Nummer hat so viele Stellen wie Nullen.',
        'insert' => 'Baustein einfügen',
        'insert_placeholder' => 'Platzhalter wählen …',
        'date_created' => 'Anlegedatum',
        'date_field_help' => 'Bestimmt Jahr und Monat der Nummer und den Neubeginn. Wählbar sind Pflichtfelder vom Typ Datum.',
        'start_help' => 'Erste Nummer je Zeitraum.',
        'next' => 'Nächste Nummer: :number',
        'assigned_on_save' => 'wird beim Speichern vergeben',
    ],
    'resets' => [
        'never' => 'nie',
        'yearly' => 'jährlich',
        'monthly' => 'monatlich',
    ],
    'months' => [
        1 => 'Januar',
        2 => 'Februar',
        3 => 'März',
        4 => 'April',
        5 => 'Mai',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'August',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Dezember',
    ],
    'blocks' => [
        'year4' => 'Jahr, vierstellig',
        'year2' => 'Jahr, zweistellig',
        'month' => 'Monat',
        'counter' => 'laufende Nummer, aufgefüllt',
        'counter_plain' => 'laufende Nummer, ohne Auffüllen',
    ],
    'errors' => [
        'reset' => 'Unbekannter Neubeginn „:reset“ (nie, jährlich oder monatlich).',
        'fiscal_start' => 'Das Geschäftsjahr beginnt in einem Monat von 1 bis 12.',
        'start' => 'Der Startwert ist mindestens 1.',
        'counter' => 'Das Muster „:pattern“ braucht genau einen Platzhalter für die laufende Nummer, z. B. {0000} oder {#}.',
        'needs_yearly' => 'Bei jährlichem Neubeginn braucht das Muster „:pattern“ ein Jahr ({YYYY} oder {YY}), sonst entstünden doppelte Nummern.',
        'needs_monthly' => 'Bei monatlichem Neubeginn braucht das Muster „:pattern“ Jahr und Monat ({YYYY} bzw. {YY} und {MM}), sonst entstünden doppelte Nummern.',
        'too_long' => 'Nummern nach dem Muster „:pattern“ wären länger als :max Zeichen.',
        'characters' => 'Im Muster „:pattern“ sind ausser den Platzhaltern nur Buchstaben, Ziffern, Leerzeichen und - / . _ erlaubt.',
        'placeholder' => 'Unbekannter Platzhalter :placeholder. Möglich sind {YYYY}, {YY}, {MM}, {0000} und {#}.',
        'date_field' => 'Das Bezugsdatum „:field“ ist kein Feld vom Typ Datum bzw. Datum und Uhrzeit dieser Tabelle.',
        'date_field_required' => 'Das Bezugsdatum „:field“ muss ein Pflichtfeld sein, damit jede Nummer ein Datum hat.',
        'date_field_in_use' => 'Das Feld „:name“ ist das Bezugsdatum des Nummernkreises „:field“ und bleibt deshalb ein aktives Pflichtfeld vom Typ Datum.',
        'date_missing' => 'Für die Nummer „:field“ fehlt das Bezugsdatum „:date“.',
        'given' => 'Die Nummer „:field“ vergibt SAFT beim Speichern; sie kann nicht vorgegeben werden.',
        'immutable' => 'Die Nummer „:field“ ist vergeben und kann nicht geändert werden.',
        'type_change' => 'Der Typ von „:name“ lässt sich von oder zu „Nummernkreis“ nur ändern, solange das Feld in keinem Datensatz einen Wert hat (auch nicht im Papierkorb).',
    ],
];
