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
