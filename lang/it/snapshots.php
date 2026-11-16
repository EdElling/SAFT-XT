<?php

declare(strict_types=1);

return [
    'title' => 'Stati dei dati',
    'description' => 'Copie immutabili di questa tabella per i report. I report usano sempre lo stato completo più recente. Le cancellazioni nella fonte hanno effetto solo con lo stato successivo; la conservazione rimuove gli stati più vecchi.',
    'refresh' => 'Aggiorna ora',
    'refreshed' => 'Stato acquisito: :rows righe',
    'retention' => 'Conservazione',
    'keep' => 'Mantieni gli ultimi stati',
    'keep_help' => 'Almeno 1; lo stato più recente resta sempre.',
    'max_days' => 'Al massimo giorni',
    'max_days_help' => 'Vuoto = senza limite.',
    'retention_saved' => 'Conservazione salvata',
    'delete' => 'Elimina',
    'delete_all' => 'Elimina tutti gli stati',
    'delete_all_description' => 'Tutti gli stati di questa tabella vengono eliminati. I report su di essa funzionano di nuovo solo dopo il prossimo aggiornamento.',
    'deleted' => 'Stato eliminato',
    'deleted_all' => 'Tutti gli stati eliminati',
    'time' => 'Data e ora',
    'rows' => 'Righe',
    'status' => 'Stato',
    'by' => 'Avviato da',
    'automatic' => 'automatico',
    'message' => 'Messaggio',
    'statuses' => [
        'running' => 'in corso',
        'complete' => 'completo',
        'failed' => 'non riuscito',
    ],
    'none' => 'Ancora nessuno stato',
    'as_of' => 'Stato dei dati: :table (:connection) del :time',
    'errors' => [
        'failed' => 'Non è stato possibile acquisire lo stato; quello precedente resta valido. Dettagli nel registro dell’applicazione.',
        'too_large' => 'La tabella ha più di :max righe – troppo grande per uno stato.',
        'not_external' => 'Gli stati esistono solo per le tabelle collegate.',
        'busy' => 'Questa tabella è in fase di aggiornamento. Riprovare più tardi.',
        'aborted' => 'Interrotto (il processo è stato interrotto).',
    ],
];
