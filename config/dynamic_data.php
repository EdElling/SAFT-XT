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
    | Grösster Export in Zeilen (0 = ohne Grenze). Der Export läuft als Arbeit blockweise in eine Datei (Fundament 2,
    | F3b); die Grenze schützt den Speicherplatz unter storage/app/private/work. Grössere Exporte werden abgelehnt,
    | nicht gekürzt. export_chunk: Zeilen je Block.
    */
    'export_max_rows' => (int) env('APP_DATA_EXPORT_MAX_ROWS', 500000),
    'export_chunk' => (int) env('APP_DATA_EXPORT_CHUNK', 1000),

    /*
    | Grenzen des Tabellen-Imports aus CSV/Excel. Der Import läuft synchron im Request; größere
    | Dateien werden mit einer Meldung abgelehnt.
    */
    /*
    | Datensatz kopieren samt Kind-Datensätzen (B-123): höchstens so viele Datensätze je Kopie, Original mitgezählt.
    */
    'copy_max_records' => (int) env('APP_DATA_COPY_MAX_RECORDS', 1000),

    /*
    | Dateien in Feldern vom Typ „Datei“ (B-021): Laravel-Speicher, nie öffentlich – Herunterladen nur über SAFT mit
    | den Rechten des Datensatzes. Vorgabe ist der private lokale Speicher (storage/app/private), wahlweise S3
    | (FILESYSTEM_DISK-Einträge in config/filesystems.php, Zugang über AWS_* in der .env).
    */
    'files' => [
        'disk' => env('APP_DATA_FILES_DISK', 'local'),
        'directory' => env('APP_DATA_FILES_DIRECTORY', 'files'),
        // Vorgabe und Obergrenze für die Höchstgrösse je Datei (MB); PHP begrenzt zusätzlich (upload_max_filesize).
        'default_max_mb' => (int) env('APP_DATA_FILES_DEFAULT_MAX_MB', 10),
        'limit_max_mb' => (int) env('APP_DATA_FILES_LIMIT_MAX_MB', 200),
        'max_files' => 50,
        // Vorschaubilder für Listen: längste Seite in Pixeln.
        'thumbnail_size' => 160,
    ],

    'import' => [
        'max_file_mb' => (int) env('APP_DATA_IMPORT_MAX_FILE_MB', 20),
        'max_rows' => (int) env('APP_DATA_IMPORT_MAX_ROWS', 50000),
        'max_columns' => (int) env('APP_DATA_IMPORT_MAX_COLUMNS', 200),
        // Datenimport in eine bestehende Tabelle: Datensatz für Datensatz wie beim Erfassen (Vorgaben, berechnete
        // Felder, Summen in übergeordneten Datensätzen) – daher eine kleinere Grenze.
        'max_record_rows' => (int) env('APP_DATA_IMPORT_MAX_RECORD_ROWS', 10000),
    ],
];
