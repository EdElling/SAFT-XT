<?php

declare(strict_types=1);

return [
    /*
    | Vorgabe, wenn weder Benutzer noch Browser etwas festlegen. Eigener Wert, denn
    | app()->setLocale() überschreibt zur Laufzeit config('app.locale').
    */
    'default' => env('APP_LOCALE', 'de'),

    /*
    | Sprachen der Oberfläche. Eine neue Sprache braucht einen Eintrag hier und ihre Texte in lang/.
    | date, datetime und time: PHP-Formate für die Anzeige. xlsx_*: Excel-Formate der Export-Datei.
    | csv_*: Schreibweise im CSV-Export, passend zu dem, was Excel in dieser Sprache erwartet.
    */
    /*
    | Regionen für das Zahlenformat (App-Einstellung „Zahlenformat“): Die Anzeige nutzt dann die Variante der Sprache
    | für diese Region (z. B. de_CH: 1'234.50), die Einträge hier überschreiben die Schreibweisen der Sprache.
    | CSV in der Schweiz: Excel erwartet den Dezimalpunkt und das Semikolon als Trennzeichen.
    */
    'regions' => [
        'CH' => [
            'csv_decimal' => '.',
            'csv_delimiter' => ';',
        ],
    ],

    'available' => [
        'de' => [
            'label' => 'Deutsch',
            'date' => 'd.m.Y',
            'datetime' => 'd.m.Y H:i',
            'time' => 'H:i',
            'xlsx_date' => 'dd.mm.yyyy',
            'xlsx_datetime' => 'dd.mm.yyyy hh:mm',
            'csv_delimiter' => ';',
            'csv_decimal' => ',',
        ],
        'en' => [
            'label' => 'English',
            'date' => 'd/m/Y',
            'datetime' => 'd/m/Y H:i',
            'time' => 'H:i',
            'xlsx_date' => 'dd/mm/yyyy',
            'xlsx_datetime' => 'dd/mm/yyyy hh:mm',
            'csv_delimiter' => ',',
            'csv_decimal' => '.',
        ],
        'fr' => [
            'label' => 'Français',
            'date' => 'd/m/Y',
            'datetime' => 'd/m/Y H:i',
            'time' => 'H:i',
            'xlsx_date' => 'dd/mm/yyyy',
            'xlsx_datetime' => 'dd/mm/yyyy hh:mm',
            'csv_delimiter' => ';',
            'csv_decimal' => ',',
        ],
        'it' => [
            'label' => 'Italiano',
            'date' => 'd/m/Y',
            'datetime' => 'd/m/Y H:i',
            'time' => 'H:i',
            'xlsx_date' => 'dd/mm/yyyy',
            'xlsx_datetime' => 'dd/mm/yyyy hh:mm',
            'csv_delimiter' => ';',
            'csv_decimal' => ',',
        ],
    ],
];
