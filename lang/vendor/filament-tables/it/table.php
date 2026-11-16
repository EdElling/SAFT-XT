<?php

declare(strict_types=1);

// Ergänzt Texte, die in Filaments eigener Übersetzung fehlen.
return [
    'actions' => [
        'move_record_down' => [
            'label' => 'Sposta giù :label',
        ],
        'move_record_up' => [
            'label' => 'Sposta su :label',
        ],
        'reorder_record' => [
            'label' => 'Riordina l’elemento :key',
        ],
        'toggle_record_content' => [
            'label' => 'Espandi/comprimi l’elemento :key',
        ],
    ],
    'column_manager' => [
        'actions' => [
            'move_down' => [
                'label' => 'Sposta giù :column',
            ],
            'move_up' => [
                'label' => 'Sposta su :column',
            ],
            'reorder' => [
                'label' => 'Riordina colonna',
            ],
        ],
        'position' => 'Posizione :position di :count.',
        'position_in_group' => 'Posizione :position di :count in :group.',
        'reordered' => ':column spostata alla posizione :position di :count.',
        'reordered_deferred' => ':column spostata alla posizione :position di :count. Applica le colonne per salvare le modifiche.',
        'reordered_in_group' => ':column spostata alla posizione :position di :count in :group.',
        'reordered_in_group_deferred' => ':column spostata alla posizione :position di :count in :group. Applica le colonne per salvare le modifiche.',
    ],
    'columns' => [
        'editable' => [
            'failure' => 'Non è stato possibile confermare il salvataggio. Aggiorna la pagina prima di riprovare.',
            'saved' => 'Modifica salvata.',
            'saving' => 'Salvataggio della modifica…',
            'unavailable' => 'Questo elemento non può più essere modificato.',
        ],
        'icon' => [
            'boolean' => [
                'false' => 'No',
                'true' => 'Sì',
            ],
        ],
    ],
    'loading' => 'Caricamento…',
    'reordering' => [
        'moved' => ':label spostato alla posizione :position di :count.',
        'position' => ':label, posizione :position di :count.',
    ],
    'result_count' => '{0} Nessun risultato|{1} :count risultato|[2,*] :count risultati',
];
