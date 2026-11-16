<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\Models\DatabaseConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use Pdo\Mysql;

/**
 * Stellt externe Verbindungen zur Laufzeit als eigene Laravel-Verbindung „ext_<id>“ bereit.
 *
 * Abgeschottet von den eigenen Verbindungen: Ein Name außerhalb des Präfixes wird nie
 * konfiguriert, und Host sowie Datenbankname werden vor dem Aufbau der DSN geprüft.
 */
final class ConnectionRegistry
{
    /** Hostname, IPv4 oder IPv6 in eckigen Klammern; nichts, was die DSN erweitern könnte. */
    public const string HOST_PATTERN = '/^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$|^\[?[0-9A-Fa-f:.]+\]?$/';

    /** Datenbank-, Benutzer- und Schemanamen: ohne Anführungszeichen, Semikolon, Leer- oder Steuerzeichen. */
    public const string NAME_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.$-]{0,127}$/';

    public function connection(DatabaseConnection $connection): Connection
    {
        $name = $connection->connectionName();
        $config = $this->config($connection);

        if (config("database.connections.{$name}") !== $config) {
            DB::purge($name);
            config(["database.connections.{$name}" => $config]);
        }

        return DB::connection($name);
    }

    /** Schließt die Verbindung, z. B. nach einem Test oder einer Änderung der Zugangsdaten. */
    public function forget(DatabaseConnection $connection): void
    {
        $name = $connection->connectionName();
        DB::purge($name);
        config(["database.connections.{$name}" => null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(DatabaseConnection $connection): array
    {
        if ($connection->getKey() === null || ! str_starts_with($connection->connectionName(), DatabaseConnection::CONNECTION_PREFIX)) {
            throw new InvalidArgumentException('Externe Verbindung ohne gespeicherten Datensatz.');
        }

        $this->assertSafe($connection);

        return match ($connection->driver) {
            ConnectionDriver::Pgsql => [
                'driver' => 'pgsql',
                'host' => trim($connection->host, '[]'),
                'port' => $connection->port,
                'database' => $connection->database,
                'username' => $connection->username,
                'password' => (string) $connection->password,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => $connection->schemaName(),
                'sslmode' => $connection->ssl_mode ?: 'prefer',
                'application_name' => 'SAFT',
                'options' => [PDO::ATTR_TIMEOUT => max(1, $connection->connect_timeout)],
                'server_options' => array_filter([
                    'statement_timeout' => max(1, $connection->statement_timeout) * 1000,
                    // „Nur lesen“ setzt die Datenbank selbst durch, nicht nur die Oberfläche.
                    'default_transaction_read_only' => $connection->is_read_only ? 'on' : null,
                ]),
            ],
            ConnectionDriver::Mysql, ConnectionDriver::Mariadb => [
                'driver' => $connection->driver->value,
                'host' => trim($connection->host, '[]'),
                'port' => $connection->port,
                'database' => $connection->database,
                'username' => $connection->username,
                'password' => (string) $connection->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => [
                    PDO::ATTR_TIMEOUT => max(1, $connection->connect_timeout),
                    // „Nur lesen“ setzt die Datenbank selbst durch (auch DDL). Zeitlimit: MySQL max_execution_time
                    // (Millisekunden, nur SELECT), MariaDB max_statement_time (Sekunden).
                    Mysql::ATTR_INIT_COMMAND => sprintf(
                        'SET SESSION transaction_read_only = %s, SESSION %s',
                        $connection->is_read_only ? 'ON' : 'OFF',
                        $connection->driver === ConnectionDriver::Mariadb
                            ? 'max_statement_time = '.max(1, $connection->statement_timeout)
                            : 'max_execution_time = '.(max(1, $connection->statement_timeout) * 1000),
                    ),
                    ...$this->mysqlTls($connection->ssl_mode ?: $connection->driver->defaultSslMode()),
                ],
            ],
            // SQL Server kennt weder ein Vorgabeschema je Verbindung noch „nur lesen“ oder ein
            // Zeitlimit auf dem Server: Abfragen werden voll qualifiziert, das Zeitlimit setzt der
            // Treiber durch, „nur lesen“ SAFT (siehe ConnectionDriver::enforcesReadOnly()).
            ConnectionDriver::Sqlsrv => [
                'driver' => 'sqlsrv',
                'host' => trim($connection->host, '[]'),
                'port' => $connection->port,
                'database' => $connection->database,
                'username' => $connection->username,
                'password' => (string) $connection->password,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'appname' => 'SAFT',
                'login_timeout' => max(1, $connection->connect_timeout),
                ...$this->sqlServerTls($connection->ssl_mode ?: ConnectionDriver::Sqlsrv->defaultSslMode()),
                'options' => defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')
                    ? [constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') => max(1, $connection->statement_timeout)]
                    : [],
            ],
        };
    }

    /**
     * ODBC Driver 18 verschlüsselt standardmäßig; require nimmt jedes Zertifikat an (z. B. selbstsigniert),
     * verify-full prüft es.
     *
     * @return array{encrypt: string, trust_server_certificate: string}
     */
    private function sqlServerTls(string $mode): array
    {
        return match ($mode) {
            'disable' => ['encrypt' => 'no', 'trust_server_certificate' => 'true'],
            'verify-full' => ['encrypt' => 'yes', 'trust_server_certificate' => 'false'],
            default => ['encrypt' => 'yes', 'trust_server_certificate' => 'true'],
        };
    }

    /**
     * PHP verschlüsselt MySQL-Verbindungen nur mit gesetzter SSL-Option; die CA-Datei des Systems
     * schaltet TLS ein und dient bei verify-full zur Prüfung des Serverzertifikats.
     *
     * @return array<int, mixed>
     */
    private function mysqlTls(string $mode): array
    {
        if ($mode === 'disable') {
            return [];
        }

        return [
            Mysql::ATTR_SSL_CA => openssl_get_cert_locations()['default_cert_file'],
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => $mode === 'verify-full',
        ];
    }

    private function assertSafe(DatabaseConnection $connection): void
    {
        $checks = [
            'host' => [$connection->host, self::HOST_PATTERN],
            'database' => [$connection->database, self::NAME_PATTERN],
            'username' => [$connection->username, self::NAME_PATTERN],
        ];

        if (filled($connection->default_schema)) {
            $checks['default_schema'] = [$connection->default_schema, self::NAME_PATTERN];
        }

        foreach ($checks as $attribute => [$value, $pattern]) {
            if (preg_match($pattern, (string) $value) !== 1) {
                throw new InvalidArgumentException("Ungültiger Wert für {$attribute}.");
            }
        }

        if (filled($connection->ssl_mode) && ! in_array($connection->ssl_mode, $connection->driver->sslModes(), true)) {
            throw new InvalidArgumentException('Ungültiger TLS-Modus.');
        }
    }
}
