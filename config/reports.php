<?php

declare(strict_types=1);

use App\Reports\Dialects\MySqlFamilyQueryDialect;
use App\Reports\Dialects\PostgresQueryDialect;

return [
    /*
    | Grenzen der Report-Engine: Zeilen in der Vorschau und im Ergebnis, Zeitlimit je Abfrage
    | (Sekunden), Spaltenwerte einer Kreuztabelle.
    */
    'preview_rows' => (int) env('REPORTS_PREVIEW_ROWS', 100),
    'max_rows' => (int) env('REPORTS_MAX_ROWS', 5000),
    'timeout' => (int) env('REPORTS_TIMEOUT', 30),
    'max_crosstab_columns' => (int) env('REPORTS_MAX_CROSSTAB_COLUMNS', 50),

    /*
    | Datenstände angebundener Tabellen: höchstens so viele Zeilen je Stand (synchrones Kopieren).
    */
    'snapshot_max_rows' => (int) env('REPORTS_SNAPSHOT_MAX_ROWS', 200000),

    // Älter als so viele Stunden: Beim Auswählen im Editor fragen, ob ein neuer Datenstand erzeugt werden soll.
    'snapshot_stale_hours' => (int) env('REPORTS_SNAPSHOT_STALE_HOURS', 24),

    /*
    | Abfrage-Dialekte je Datenbanktreiber: die einzigen datenbankspezifischen Ausdrücke.
    */
    'dialects' => [
        'pgsql' => PostgresQueryDialect::class,
        'mysql' => MySqlFamilyQueryDialect::class,
        'mariadb' => MySqlFamilyQueryDialect::class,
    ],
];
