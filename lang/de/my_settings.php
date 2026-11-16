<?php

declare(strict_types=1);

return [
    'title' => 'Meine Einstellungen',
    'appearance' => 'Darstellung',
    'high_contrast' => 'Hoher Kontrast',
    'high_contrast_help' => 'Gilt nur für Ihr Konto, auf allen Geräten.',
    'saved' => 'Gespeichert',
    'high_contrast_options' => [
        'auto' => 'Automatisch',
        'on' => 'Ein',
        'off' => 'Aus',
    ],
    'high_contrast_descriptions' => [
        'auto' => 'Hoher Kontrast, wenn im Betriebssystem „Mehr Kontrast“ eingestellt ist.',
        'on' => 'Dunklere bzw. hellere Schrift (mindestens 7:1), kräftige Rahmen, deutlicher Fokus, unterstrichene Links.',
        'off' => 'Normale Darstellung.',
    ],
    'number_format' => 'Zahlenformat',
    'number_format_help' => 'Gilt nur für Ihr Konto, auf allen Geräten: Zahlen in Listen, Details, Auswertungen und im CSV-Export.',
    'number_format_options' => [
        'auto' => 'Automatisch',
        'lang' => 'Wie die Sprache',
        'CH' => 'Schweiz',
    ],
    'number_format_descriptions' => [
        'auto' => 'Schweizer Format, wenn Ihr Browser auf die Schweiz eingestellt ist (z. B. Deutsch (Schweiz)), sonst wie die Sprache.',
        'lang' => '1.234,50 (Deutsch, Italiano) · 1,234.50 (English) · 1 234,50 (Français)',
        'CH' => '1\'234.50 – auf Französisch nach Schweizer Regel 1 234,50; CSV mit Dezimalpunkt.',
    ],
];
