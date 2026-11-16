<?php

declare(strict_types=1);

return [
    'plugin' => 'Tabellone delle giacenze',
    'description' => 'Plugin di esempio per le guide del manuale dello sviluppatore: matrice delle giacenze, tabellone con trascinamento, riquadro, listener e regola di convalida per gli articoli (demo magazzino, richiede Trasferimento di magazzino).',
    'permissions' => [
        'view' => 'Visualizzare la matrice delle giacenze',
        'transfer' => 'Trasferire sul tabellone delle giacenze',
    ],
    'unavailable_title' => 'Demo magazzino incompleta',
    'unavailable' => 'Questa pagina richiede tabelle e campi della demo magazzino. Manca:',
    'no_rights' => 'Non hai il diritto di visualizzare articoli, ubicazioni e giacenze.',
    'actions' => [
        'matrix' => 'Matrice delle giacenze',
        'board' => 'Distribuire sul tabellone',
    ],
    'rules' => [
        'article_number' => 'Codice articolo (demo magazzino)',
        'article_number_invalid' => 'Codice articolo atteso nel formato A-1234 (una lettera, un trattino, quattro cifre).',
        'article_number_description' => 'Una lettera maiuscola, un trattino, quattro cifre (A-1234). Minuscole e spazi sono ammessi; il codice viene salvato in maiuscolo senza spazi.',
    ],
    'errors' => [
        'number_locked' => 'Il codice articolo :number è usato nei movimenti di magazzino e non può più essere modificato.',
    ],
    'matrix' => [
        'title' => 'Matrice delle giacenze',
        'number' => 'Codice',
        'article' => 'Articolo',
        'total' => 'Totale',
        'minimum' => 'Scorta minima',
        'below_minimum' => 'Solo sotto la scorta minima',
        'empty' => 'Nessun articolo',
    ],
    'board' => [
        'title' => 'Tabellone delle giacenze',
        'article' => 'Articolo',
        'help' => 'Trascina la giacenza su un’altra ubicazione per trasferirla.',
        'drag' => 'Trascina su un’altra ubicazione',
        'empty' => 'Nessuna giacenza',
        'modal' => 'Trasferire da :from a :to',
        'submit' => 'Trasferire',
        'quantity' => 'Quantità',
        'rights' => 'Ti mancano diritti sui dati per: :tables',
        'invalid' => 'Il trasferimento viola regole delle tabelle:',
        'done' => ':count trasferiti da :from a :to.',
    ],
    'tasks' => [
        'unavailable' => 'Demo magazzino incompleta: :missing',
        'inventory' => [
            'label' => 'Magazzino: promemoria inventario',
            'days' => 'Non contato da (giorni)',
            'days_help' => 'Vengono segnalate le giacenze con merce il cui ultimo conteggio è più vecchio o manca.',
            'describe' => 'Giacenze non contate da :days giorni',
            'no_field' => 'La tabella Giacenze non ha il campo «letzte_zaehlung».',
            'item' => ':article (:location)',
            'none' => 'Tutte le giacenze sono state contate negli ultimi :days giorni.',
            'found' => '{1} 1 giacenza non contata da oltre :days giorni: :list|[2,*] :count giacenze non contate da oltre :days giorni: :list',
            'more' => ':list e altre :count',
        ],
        'minimum' => [
            'label' => 'Magazzino: segnalare la scorta minima',
            'describe' => 'Notifica alle persone con il diritto «Visualizzare la matrice delle giacenze»',
            'no_field' => 'La tabella Articoli non ha il campo «mindestbestand».',
            'none' => 'Tutti gli articoli sono sopra la scorta minima; nessuno è stato notificato.',
            'title' => '{1} 1 articolo sotto la scorta minima|[2,*] :count articoli sotto la scorta minima',
            'more' => ':list e altri :count',
            'open' => 'Aprire la matrice delle giacenze',
            'done' => ':count articoli sotto la scorta minima, :people persone notificate.',
        ],
    ],
    'widget' => [
        'label' => 'Magazzino: giacenza per ubicazione',
        'description' => 'Somma di tutti gli articoli',
        'quantity' => 'Giacenza',
    ],
];
