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
