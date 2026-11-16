<?php

declare(strict_types=1);

return [
    'title' => 'Le mie impostazioni',
    'appearance' => 'Aspetto',
    'high_contrast' => 'Contrasto elevato',
    'high_contrast_help' => 'Vale solo per il tuo account, su tutti i dispositivi.',
    'saved' => 'Salvato',
    'high_contrast_options' => [
        'auto' => 'Automatico',
        'on' => 'Attivo',
        'off' => 'Disattivo',
    ],
    'high_contrast_descriptions' => [
        'auto' => 'Contrasto elevato se nel sistema operativo è impostato «Aumenta contrasto».',
        'on' => 'Testo più scuro o più chiaro (almeno 7:1), bordi marcati, focus ben visibile, link sottolineati.',
        'off' => 'Visualizzazione normale.',
    ],
    'number_format' => 'Formato dei numeri',
    'number_format_help' => 'Vale solo per il vostro account, su tutti i dispositivi: numeri negli elenchi, nei dettagli, nei report e nell’esportazione CSV.',
    'number_format_options' => [
        'auto' => 'Automatico',
        'lang' => 'Come la lingua',
        'CH' => 'Svizzera',
    ],
    'number_format_descriptions' => [
        'auto' => 'Formato svizzero se il browser è impostato sulla Svizzera (ad es. italiano (Svizzera)), altrimenti come la lingua.',
        'lang' => '1.234,50 (Deutsch, Italiano) · 1,234.50 (English) · 1 234,50 (Français)',
        'CH' => '1\'234.50 – in francese secondo la regola svizzera 1 234,50; CSV con punto decimale.',
    ],
    'two_factor' => 'Autenticazione a due fattori',
    'two_factor_help' => 'Protegge il vostro account anche se qualcuno conosce la vostra password: all’accesso inserite anche un codice di un’app di autenticazione. Conservate i codici di recupero in un luogo sicuro – con essi potete accedere anche senza telefono.',
    'two_factor_required' => 'Per il vostro account l’autenticazione a due fattori è obbligatoria. Conservate i codici di recupero in un luogo sicuro – con essi potete accedere anche senza telefono.',
];
