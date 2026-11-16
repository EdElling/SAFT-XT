<?php

declare(strict_types=1);

return [
    'plugin' => 'Trasferimento di magazzino',
    'description' => 'Plugin di esempio: trasferire merce tra ubicazioni; i movimenti aggiornano la giacenza; avviso sotto la scorta minima (demo magazzino).',
    'permission' => 'Eseguire trasferimenti',
    'title' => 'Trasferimento',
    'help' => 'Trasferisce merce da un’ubicazione a un’altra. Le giacenze di entrambe le ubicazioni vengono aggiornate e si creano due movimenti di tipo «Trasferimento» (−/+).',
    'fields' => [
        'article' => 'Articolo',
        'from' => 'Dall’ubicazione',
        'to' => 'All’ubicazione',
        'quantity' => 'Quantità',
        'note' => 'Nota',
    ],
    'submit' => 'Trasferisci',
    'available' => 'Disponibile all’origine: :count',
    'location_with_stock' => ':location (giacenza: :count)',
    'no_stock_here' => 'Nessuna giacenza in questa ubicazione',
    'no_stock' => 'Questo articolo non ha giacenza in alcuna ubicazione.',
    'unavailable_title' => 'Demo magazzino incompleta',
    'unavailable' => 'Questa pagina richiede tabelle e campi della demo magazzino. Manca:',
    'done' => ':count × :article trasferito/i da :from a :to.',
    'minimum' => [
        'title' => 'Scorta minima non raggiunta',
        'body' => 'Giacenza totale di «:article»: :count (scorta minima :minimum).',
    ],
    'errors' => [
        'same_location' => 'Ubicazione di origine e di destinazione devono essere diverse.',
        'quantity' => 'La quantità deve essere almeno 1.',
        'not_enough' => 'Giacenza insufficiente all’origine (disponibile: :available).',
        'invalid' => 'Il trasferimento viola regole delle tabelle:',
        'negative' => 'Giacenza insufficiente: «:article» nell’ubicazione :location ne ha :available; il movimento la renderebbe negativa.',
        'stock_locked' => 'Le giacenze sono gestite tramite i movimenti di magazzino. Registri le correzioni come movimento di tipo «Inventario».',
        'rights' => 'Le mancano i diritti sui dati per: :tables',
    ],
    'widget' => [
        'label' => 'Magazzino: scorta minima',
        'below' => 'Articoli sotto la scorta minima',
        'none' => 'Tutti gli articoli sopra la scorta minima',
        'item' => ':article (:count/:minimum)',
        'more' => ':names e altri :count',
        'total' => 'Scorta totale (pezzi)',
        'transfer' => 'Al trasferimento',
    ],
];
