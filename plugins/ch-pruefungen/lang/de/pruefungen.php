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
    'descriptions' => [
        'ahv' => '13 Ziffern, beginnend mit 756, mit Prüfziffer (EAN-13); Punkte und Leerzeichen sind erlaubt, z. B. 756.1234.5678.97.',
        'iban' => 'Länderkürzel, zwei Prüfziffern und Kontonummer; Prüfsumme nach ISO 13616, für CH und LI genau 21 Zeichen; Leerzeichen sind erlaubt.',
        'uid' => 'CHE, dann neun Ziffern mit Prüfziffer, z. B. CHE-123.456.789; Bindestrich und Punkte dürfen fehlen.',
        'plz' => 'Vier Ziffern von 1000 bis 9999.',
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
