<?php

declare(strict_types=1);
use App\DynamicData\Ddl\MariaDbDialect;
use App\DynamicData\Ddl\MySqlDialect;
use App\DynamicData\Ddl\PostgresDialect;

return [
    /*
    | Verbindung für Tabellen des Table-Designers. Sie arbeitet ausschließlich im
    | Namensraum unten: PostgreSQL ein Schema (app_data), MySQL/MariaDB eine eigene Datenbank
    | (<Datenbank>_app_data). Für Produktion empfohlen: eigener DB-Benutzer mit Rechten nur
    | auf diesen Namensraum (DB_APP_DATA_USERNAME / _PASSWORD).
    */
    'connection' => env('APP_DATA_CONNECTION', 'app_data'),

    'namespace' => env('APP_DATA_SCHEMA', match (env('DB_CONNECTION')) {
        'mysql' => env('DB_DATABASE', env('MYSQL_DATABASE', 'laravel')).'_app_data',
        'mariadb' => env('DB_DATABASE', env('MARIADB_DATABASE', 'laravel')).'_app_data',
        default => 'app_data',
    }),

    /*
    | DDL-Dialekte je Datenbanktreiber. Weitere (z. B. sqlsrv) werden durch
    | eine Klasse, die App\DynamicData\Ddl\DdlDialect implementiert, ergänzt.
    */
    'dialects' => [
        'pgsql' => PostgresDialect::class,
        'mysql' => MySqlDialect::class,
        'mariadb' => MariaDbDialect::class,
    ],

    /*
    | Größter Export in Zeilen. Der Download läuft über Livewire, das die Datei vollständig im
    | Speicher hält. Größere Exporte werden abgelehnt, nicht gekürzt.
    */
    'export_max_rows' => (int) env('APP_DATA_EXPORT_MAX_ROWS', 50000),

    /*
    | Grenzen des Tabellen-Imports aus CSV/Excel. Der Import läuft synchron im Request; größere
    | Dateien werden mit einer Meldung abgelehnt.
    */
    'import' => [
        'max_file_mb' => (int) env('APP_DATA_IMPORT_MAX_FILE_MB', 20),
        'max_rows' => (int) env('APP_DATA_IMPORT_MAX_ROWS', 50000),
        'max_columns' => (int) env('APP_DATA_IMPORT_MAX_COLUMNS', 200),
        // Datenimport in eine bestehende Tabelle: Datensatz für Datensatz wie beim Erfassen (Vorgaben, berechnete
        // Felder, Summen in übergeordneten Datensätzen) – daher eine kleinere Grenze.
        'max_record_rows' => (int) env('APP_DATA_IMPORT_MAX_RECORD_ROWS', 10000),
    ],
];
