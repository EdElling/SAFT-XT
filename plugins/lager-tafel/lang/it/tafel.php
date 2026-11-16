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
    'widget' => [
        'label' => 'Magazzino: giacenza per ubicazione',
        'description' => 'Somma di tutti gli articoli',
        'quantity' => 'Giacenza',
    ],
];
