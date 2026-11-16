<?php

declare(strict_types=1);

return [
    'plugin' => 'Swiss checks',
    'description' => 'Example plugin: validation rules for AHV number, IBAN, UID and postal code; formula function rappen() for rounding to 5 centimes.',
    'rules' => [
        'ahv' => 'AHV number',
        'iban' => 'IBAN',
        'uid' => 'UID (CHE number)',
        'plz' => 'Swiss postal code',
    ],
    'descriptions' => [
        'ahv' => '13 digits starting with 756, with check digit (EAN-13); dots and spaces are allowed, e.g. 756.1234.5678.97.',
        'iban' => 'Country code, two check digits and account number; checksum according to ISO 13616, exactly 21 characters for CH and LI; spaces are allowed.',
        'uid' => 'CHE, then nine digits with check digit, e.g. CHE-123.456.789; hyphen and dots may be omitted.',
        'plz' => 'Four digits from 1000 to 9999.',
    ],
    'invalid' => [
        'ahv' => 'Not a valid AHV number (756.xxxx.xxxx.xx, check digit).',
        'iban' => 'Not a valid IBAN (checksum or length).',
        'uid' => 'Not a valid UID (CHE-xxx.xxx.xxx, check digit).',
        'plz' => 'Not a valid Swiss postal code (four digits).',
    ],
    'functions' => [
        'rappen' => 'Rounds to 5 centimes, e.g. rappen(amount * 1.081)',
    ],
];
