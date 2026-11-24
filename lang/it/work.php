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
        'export' => 'Esportare',
        'documents' => 'Creare documenti',
        'record_import' => 'Importare dati',
        'snapshot_refresh' => 'Acquisire stato dei dati',
        'app_designer_turn' => 'Progettare app',
        'app_designer_create' => 'Creare app',
        'app_designer_reports' => 'Creare i report dell’app',
    ],
    'export' => [
        'done' => '1 riga esportata.|:count righe esportate.',
        'table_missing' => 'La tabella non esiste più.',
    ],
    'import' => [
        'table_missing' => 'La tabella non esiste più.',
        'file_missing' => 'Il file caricato non è più disponibile; riavviare l’importazione.',
    ],
    'recompute' => [
        'all_tables' => 'tutte le tabelle con campi calcolati',
    ],
    'cancelled' => 'Annullato.',
    'aborted_repeatedly' => 'Interrotto più volte senza concludersi (ad es. limite di tempo del server superato). Il lavoro è stato terminato.',
    'failed_generic' => 'Il lavoro non è riuscito (riferimento :reference). I dettagli si trovano nel registro di sistema.',
    'plugin_inactive' => 'Il plugin «:plugin» è inattivo; il lavoro non è stato eseguito.',
    'started' => 'Lavoro avviato (:id); lo scheduler lo esegue in background.',
    'my_work' => 'I miei lavori',
    'empty' => 'Nessun lavoro',
    'system' => 'Sistema',
    'tabs' => [
        'all' => 'Tutti',
        'mine' => 'I miei',
    ],
    'sections' => [
        'details' => 'Dati',
    ],
    'fields' => [
        'type' => 'Tipo',
        'description' => 'Descrizione',
        'user' => 'Avviato da',
        'app' => 'App',
        'status' => 'Stato',
        'progress' => 'Avanzamento',
        'created' => 'Avviato',
        'finished' => 'Terminato',
        'duration' => 'Durata',
        'message' => 'Messaggio',
    ],
    'actions' => [
        'download' => 'Scarica il risultato',
        'cancel' => 'Annulla',
        'cancel_heading' => 'Annullare il lavoro?',
        'cancel_requested' => 'Il lavoro viene annullato.',
        'cancelling' => 'Annullamento …',
    ],
    'progress' => [
        'driving' => 'Prosegue finché questa pagina è aperta. Se la chiude, il lavoro continua in background e alla fine riceverà una notifica.',
        'background' => 'In corso in background.',
        'expires' => 'disponibile fino al :time',
    ],
    'notifications' => [
        'done' => '«:work» è terminato',
        'failed' => '«:work» non è riuscito',
        'open' => 'Apri',
    ],
    'widget' => [
        'stuck' => '1 lavoro senza avanzamento da oltre :minutes minuti (lo scheduler è attivo?)|:count lavori senza avanzamento da oltre :minutes minuti (lo scheduler è attivo?)',
        'failed' => '1 lavoro non riuscito nelle ultime 24 ore|:count lavori non riusciti nelle ultime 24 ore',
    ],
];
