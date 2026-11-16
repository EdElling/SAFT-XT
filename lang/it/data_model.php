<?php

declare(strict_types=1);

return [
    'title' => 'Modello dati: :group',
    'title_all' => 'Modello dati',
    'show_fields' => 'Mostra campi',
    'empty' => 'Questo gruppo di menu non contiene tabelle dati che è autorizzato a vedere.',
    'load_failed' => 'Non è stato possibile caricare la libreria dei diagrammi.',
    'render_failed' => 'Non è stato possibile disegnare il diagramma.',
    'legend' => [
        'solid' => 'Linea continua: configurata come tabella di dettaglio',
        'dashed' => 'Linea tratteggiata: campo di collegamento',
        'link' => '⇄ prima dell’etichetta: collegamento tra tabelle (senza chiave esterna, vedi Amministrazione)',
        'cardinality' => 'Zampa di gallina: molti record; || esattamente uno (campo obbligatorio), o| nessuno o uno',
        'outside' => 'Bordo tratteggiato: tabella fuori da questo gruppo',
        'external' => 'Bordo arancione: tabella collegata (connessione tra parentesi)',
        'fields' => 'PK chiave, FK collegamento, UK univoco, * campo obbligatorio',
    ],
];
