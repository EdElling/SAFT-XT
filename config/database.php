<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
| DB_* (z. B. aus phpunit.xml oder .env.browser) gilt nur für die Datenbank, die DB_CONNECTION wählt.
| Die übrigen Blöcke nutzen nur ihre eigenen Einträge (PGSQL_*, MYSQL_*, MARIADB_*), z. B. für die
| externen Demo- und Testdatenbanken – sonst würde etwa DB_PORT=5432 eine MariaDB-Verbindung umlenken.
*/
$db = static fn (string $driver, string $key, string $own, mixed $default = null): mixed => env('DB_CONNECTION', 'pgsql') === $driver
    ? env('DB_'.$key, env($own, $default))
    : env($own, $default);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        // Zugangsdaten je Datenbank (MYSQL_*, MARIADB_*, PGSQL_*); DB_* hat nur für die mit DB_CONNECTION
        // gewählte Datenbank Vorrang (siehe $db oben). So können alle Blöcke in der .env stehen.
        'mysql' => $mysql = [
            'driver' => 'mysql',
            'url' => $db('mysql', 'URL', 'MYSQL_URL'),
            'host' => $db('mysql', 'HOST', 'MYSQL_HOST', '127.0.0.1'),
            'port' => $db('mysql', 'PORT', 'MYSQL_PORT', '3306'),
            'database' => $db('mysql', 'DATABASE', 'MYSQL_DATABASE', 'laravel'),
            'username' => $db('mysql', 'USERNAME', 'MYSQL_USERNAME', 'root'),
            'password' => $db('mysql', 'PASSWORD', 'MYSQL_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // Wie mysql. Dient SAFT selbst (DB_CONNECTION=mariadb) und der MariaDB-Fassung der
        // Forschungsdatenbank (Demo und Tests).
        'mariadb' => $mariadb = [
            'driver' => 'mariadb',
            'url' => $db('mariadb', 'URL', 'MARIADB_URL'),
            'host' => $db('mariadb', 'HOST', 'MARIADB_HOST', '127.0.0.1'),
            'port' => $db('mariadb', 'PORT', 'MARIADB_PORT', '3306'),
            'database' => $db('mariadb', 'DATABASE', 'MARIADB_DATABASE', 'laravel'),
            'username' => $db('mariadb', 'USERNAME', 'MARIADB_USERNAME', 'root'),
            'password' => $db('mariadb', 'PASSWORD', 'MARIADB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            // MariaDB ab 11.6 bricht bei REPEATABLE READ gleichzeitige Schreibzugriffe auf dieselbe Zeile mit
            // Fehler 1020 ab (Snapshot-Isolation), z. B. im Datenbank-Cache. READ COMMITTED wie bei PostgreSQL.
            'isolation_level' => env('MARIADB_ISOLATION_LEVEL', 'READ COMMITTED'),
            // MariaDB verschlüsselt ab 11.4 von sich aus (selbstsigniertes Zertifikat): TLS ohne Prüfung.
            'options' => extension_loaded('pdo_mysql') ? [
                Mysql::ATTR_SSL_CA => env('MARIADB_ATTR_SSL_CA', openssl_get_cert_locations()['default_cert_file']),
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => (bool) env('MARIADB_SSL_VERIFY', false),
            ] : [],
        ],

        'pgsql' => $pgsql = [
            'driver' => 'pgsql',
            'url' => $db('pgsql', 'URL', 'PGSQL_URL'),
            'host' => $db('pgsql', 'HOST', 'PGSQL_HOST', '127.0.0.1'),
            'port' => $db('pgsql', 'PORT', 'PGSQL_PORT', '5432'),
            'database' => $db('pgsql', 'DATABASE', 'PGSQL_DATABASE', 'laravel'),
            'username' => $db('pgsql', 'USERNAME', 'PGSQL_USERNAME', 'root'),
            'password' => $db('pgsql', 'PASSWORD', 'PGSQL_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        // Table-Designer & Dynamic Data: nur Namensraum app_data (siehe config/dynamic_data.php),
        // gleicher Treiber wie die Standardverbindung. MySQL/MariaDB: ohne voreingestellte Datenbank, alle
        // Tabellen sind mit dem Namensraum (eigene Datenbank) qualifiziert.
        'app_data' => [
            ...$appDataBase = match (env('DB_CONNECTION')) {
                'mysql' => [...$mysql, 'database' => ''],
                'mariadb' => [...$mariadb, 'database' => ''],
                default => [...$pgsql, 'search_path' => env('APP_DATA_SCHEMA', 'app_data')],
            },
            // Eigener Server, Benutzer und Passwort für den Namensraum app_data – z. B. bei Hostern, die je Datenbank
            // einen eigenen Benutzer und Host vergeben (Strato). Leer = wie die Standardverbindung.
            'host' => env('DB_APP_DATA_HOST') ?: $appDataBase['host'],
            'port' => env('DB_APP_DATA_PORT') ?: $appDataBase['port'],
            'username' => env('DB_APP_DATA_USERNAME') ?: $appDataBase['username'],
            'password' => env('DB_APP_DATA_PASSWORD') ?: $appDataBase['password'],
        ],

        // Nur für die SQL-Server-Fassung der Forschungsdatenbank (Demo und Tests) und als Vorlage;
        // SAFT selbst läuft auf pgsql oder mysql. Zugangsdaten MSSQL_*.
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => env('MSSQL_HOST', '127.0.0.1'),
            'port' => env('MSSQL_PORT', '1433'),
            'database' => env('MSSQL_DATABASE', 'SAFT2_ext'),
            'username' => env('MSSQL_USERNAME', 'saft'),
            'password' => env('MSSQL_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => 'yes',
            // Selbstsigniertes Zertifikat (z. B. SQL Server im Container) akzeptieren.
            'trust_server_certificate' => env('MSSQL_TRUST_SERVER_CERTIFICATE', 'true'),
            'login_timeout' => 5,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    /*
    | Sicherung (php artisan db:backup): Pfad zu pg_dump bzw. mysqldump je Treiber, falls nicht
    | im PATH oder anders benannt (z. B. MySQL als Snap: /snap/bin/mysql.mysqldump).
    */
    'backup' => [
        'binaries' => [
            'pgsql' => env('PGSQL_DUMP_BINARY'),
            'mysql' => env('MYSQL_DUMP_BINARY'),
            'mariadb' => env('MARIADB_DUMP_BINARY'),
        ],
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
