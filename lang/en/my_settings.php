<?php

declare(strict_types=1);

return [
    'title' => 'My settings',
    'appearance' => 'Appearance',
    'high_contrast' => 'High contrast',
    'high_contrast_help' => 'Applies only to your account, on all devices.',
    'saved' => 'Saved',
    'high_contrast_options' => [
        'auto' => 'Automatic',
        'on' => 'On',
        'off' => 'Off',
    ],
    'high_contrast_descriptions' => [
        'auto' => 'High contrast if “Increase contrast” is set in the operating system.',
        'on' => 'Darker or lighter text (at least 7:1), strong borders, clear focus, underlined links.',
        'off' => 'Normal appearance.',
    ],
    'number_format' => 'Number format',
    'number_format_help' => 'Applies only to your account, on all devices: numbers in lists, details, reports and the CSV export.',
    'number_format_options' => [
        'auto' => 'Automatic',
        'lang' => 'As the language',
        'CH' => 'Switzerland',
    ],
    'number_format_descriptions' => [
        'auto' => 'Swiss format if your browser is set to Switzerland (e.g. German (Switzerland)), otherwise as the language.',
        'lang' => '1.234,50 (Deutsch, Italiano) · 1,234.50 (English) · 1 234,50 (Français)',
        'CH' => '1\'234.50 – in French by the Swiss rule 1 234,50; CSV with decimal point.',
    ],
];
