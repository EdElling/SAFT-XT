<?php

declare(strict_types=1);

/** KI-App-Designer (B-107, Etappe 7a). */
return [
    'untitled' => 'Nuova bozza',
    'errors' => [
        'app_name' => 'L’app ha bisogno di un nome.',
        'prefix' => 'Il prefisso «:prefix» non è valido: 1–20 lettere minuscole e cifre, iniziando con una lettera.',
        'prefix_taken' => 'Il prefisso «:prefix» è già usato da un’altra app.',
        'no_tables' => 'La bozza non ha tabelle.',
        'table_prefix' => 'La tabella «:table» deve iniziare con il prefisso «:prefix».',
        'duplicate_table' => 'La tabella «:table» compare due volte.',
        'menu_label' => 'Un gruppo di menu non ha un’etichetta.',
        'menu_table' => 'Il gruppo di menu cita la tabella «:table», che non è nella bozza.',
        'menu_twice' => 'La tabella «:table» è in più di un gruppo di menu.',
        'invalid' => 'Nessuna bozza valida: :errors',
        'nothing_to_create' => 'Non c’è ancora una bozza da cui creare un’app.',
        'already_created' => 'Da questa bozza è già stata creata un’app.',
    ],
];
