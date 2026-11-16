<?php

declare(strict_types=1);

return [
    'plugin' => 'Verifiche svizzere',
    'description' => 'Plugin di esempio: regole di verifica per numero AVS, IBAN, IDI e numero postale; funzione per formule rappen() per l’arrotondamento a 5 centesimi.',
    'rules' => [
        'ahv' => 'Numero AVS',
        'iban' => 'IBAN',
        'uid' => 'IDI (numero CHE)',
        'plz' => 'Numero postale svizzero',
    ],
    'descriptions' => [
        'ahv' => '13 cifre che iniziano con 756, con cifra di controllo (EAN-13); punti e spazi sono ammessi, ad es. 756.1234.5678.97.',
        'iban' => 'Codice del paese, due cifre di controllo e numero di conto; somma di controllo secondo ISO 13616, esattamente 21 caratteri per CH e LI; spazi ammessi.',
        'uid' => 'CHE, poi nove cifre con cifra di controllo, ad es. CHE-123.456.789; trattino e punti possono mancare.',
        'plz' => 'Quattro cifre da 1000 a 9999.',
    ],
    'invalid' => [
        'ahv' => 'Numero AVS non valido (756.xxxx.xxxx.xx, cifra di controllo).',
        'iban' => 'IBAN non valido (somma di controllo o lunghezza).',
        'uid' => 'IDI non valido (CHE-xxx.xxx.xxx, cifra di controllo).',
        'plz' => 'Numero postale svizzero non valido (quattro cifre).',
    ],
    'functions' => [
        'rappen' => 'Arrotonda a 5 centesimi, p. es. rappen(importo * 1.081)',
    ],
];
