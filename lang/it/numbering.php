<?php

declare(strict_types=1);

// Intervalli di numerazione (B-135): tipo di campo «Intervallo di numerazione» nel designer di tabelle.
return [
    'fields' => [
        'pattern' => 'Modello',
        'reset' => 'Ripartenza',
        'date_field' => 'Data di riferimento',
        'fiscal_start' => 'L’esercizio inizia a',
        'start' => 'Valore iniziale',
    ],
    'form' => [
        'section' => 'Intervallo di numerazione',
        'pattern_help' => 'Testo fisso e segnaposto, p. es. R-{YYYY}-{0000}. Il numero progressivo ha tante cifre quanti zeri.',
        'insert' => 'Inserisci elemento',
        'insert_placeholder' => 'Scegli un segnaposto …',
        'date_created' => 'Data di creazione',
        'date_field_help' => 'Determina anno e mese del numero e la ripartenza. Si possono scegliere i campi data obbligatori.',
        'start_help' => 'Primo numero per periodo.',
        'next' => 'Prossimo numero: :number',
        'assigned_on_save' => 'assegnato al salvataggio',
    ],
    'resets' => [
        'never' => 'mai',
        'yearly' => 'annuale',
        'monthly' => 'mensile',
    ],
    'months' => [
        1 => 'gennaio',
        2 => 'febbraio',
        3 => 'marzo',
        4 => 'aprile',
        5 => 'maggio',
        6 => 'giugno',
        7 => 'luglio',
        8 => 'agosto',
        9 => 'settembre',
        10 => 'ottobre',
        11 => 'novembre',
        12 => 'dicembre',
    ],
    'blocks' => [
        'year4' => 'anno, quattro cifre',
        'year2' => 'anno, due cifre',
        'month' => 'mese',
        'counter' => 'numero progressivo, con zeri',
        'counter_plain' => 'numero progressivo, senza zeri',
    ],
    'errors' => [
        'reset' => 'Ripartenza sconosciuta «:reset» (mai, annuale o mensile).',
        'fiscal_start' => 'L’esercizio inizia in un mese da 1 a 12.',
        'start' => 'Il valore iniziale è almeno 1.',
        'counter' => 'Il modello «:pattern» richiede esattamente un segnaposto per il numero progressivo, p. es. {0000} o {#}.',
        'needs_yearly' => 'Con una ripartenza annuale il modello «:pattern» richiede un anno ({YYYY} o {YY}), altrimenti i numeri si ripeterebbero.',
        'needs_monthly' => 'Con una ripartenza mensile il modello «:pattern» richiede anno e mese ({YYYY} o {YY} e {MM}), altrimenti i numeri si ripeterebbero.',
        'too_long' => 'I numeri secondo il modello «:pattern» supererebbero :max caratteri.',
        'characters' => 'Oltre ai segnaposto, il modello «:pattern» può contenere solo lettere, cifre, spazi e - / . _',
        'placeholder' => 'Segnaposto sconosciuto :placeholder. Possibili: {YYYY}, {YY}, {MM}, {0000} e {#}.',
        'date_field' => 'La data di riferimento «:field» non è un campo data o data e ora di questa tabella.',
        'date_field_required' => 'La data di riferimento «:field» deve essere un campo obbligatorio, affinché ogni numero abbia una data.',
        'date_field_in_use' => 'Il campo «:name» è la data di riferimento dell’intervallo di numerazione «:field» e resta quindi un campo data attivo e obbligatorio.',
        'date_missing' => 'Manca la data di riferimento «:date» per il numero «:field».',
        'given' => 'SAFT assegna il numero «:field» al salvataggio; non può essere impostato.',
        'immutable' => 'Il numero «:field» è assegnato e non può essere modificato.',
        'type_change' => 'Il tipo di «:name» può passare da o verso «Intervallo di numerazione» solo finché nessun record ha un valore in questo campo (cestino compreso).',
    ],
];
