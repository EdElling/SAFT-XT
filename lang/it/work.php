<?php

declare(strict_types=1);

return [
    'model' => 'Lavoro',
    'plural' => 'Lavori',
    'status' => [
        'queued' => 'in attesa',
        'running' => 'in corso',
        'done' => 'terminato',
        'failed' => 'fallito',
        'cancelled' => 'annullato',
    ],
    'types' => [
        'recompute' => 'Ricalcolare i campi calcolati',
    ],
    'recompute' => [
        'all_tables' => 'tutte le tabelle con campi calcolati',
    ],
    'cancelled' => 'Annullato.',
    'aborted_repeatedly' => 'Interrotto più volte senza concludersi (ad es. limite di tempo del server superato). Il lavoro è stato terminato.',
    'failed_generic' => 'Il lavoro non è riuscito (riferimento :reference). I dettagli si trovano nel registro di sistema.',
    'plugin_inactive' => 'Il plugin «:plugin» è inattivo; il lavoro non è stato eseguito.',
    'started' => 'Lavoro avviato (:id); lo scheduler lo esegue in background.',
];
