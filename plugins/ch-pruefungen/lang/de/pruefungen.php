<?php

declare(strict_types=1);

return [
    'plugin' => 'CH-Prüfungen',
    'description' => 'Beispiel-Plugin: Prüfregeln für AHV-Nummer, IBAN, UID und Postleitzahl; Formelfunktion rappen() für die Rundung auf 5 Rappen.',
    'rules' => [
        'ahv' => 'AHV-Nummer',
        'iban' => 'IBAN',
        'uid' => 'UID (CHE-Nummer)',
        'plz' => 'Postleitzahl Schweiz',
    ],
    'invalid' => [
        'ahv' => 'Keine gültige AHV-Nummer (756.xxxx.xxxx.xx, Prüfziffer).',
        'iban' => 'Keine gültige IBAN (Prüfsumme oder Länge).',
        'uid' => 'Keine gültige UID (CHE-xxx.xxx.xxx, Prüfziffer).',
        'plz' => 'Keine gültige Schweizer Postleitzahl (vier Ziffern).',
    ],
    'functions' => [
        'rappen' => 'Rundet auf 5 Rappen, z. B. rappen(betrag * 1.081)',
    ],
];
