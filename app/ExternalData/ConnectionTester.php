<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\Models\DatabaseConnection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Prüft eine gespeicherte Verbindung: Anmeldung, Serverversion, Schemas und das Vorgabeschema.
 * Das Ergebnis wird am Datensatz gespeichert; Meldungen enthalten nie das Passwort.
 */
final class ConnectionTester
{
    public function __construct(private readonly ConnectionRegistry $registry) {}

    public function test(DatabaseConnection $connection): ConnectionTestResult
    {
        $result = $this->run($connection);

        $connection->forceFill([
            'last_tested_at' => now(),
            'last_test_successful' => $result->successful,
            'last_test_message' => Str::limit($result->message, 1000),
            'known_schemas' => $result->schemas === [] ? $connection->known_schemas : $result->schemas,
        ])->save();

        return $result;
    }

    /**
     * Schemas (MySQL: Datenbanken) ohne die internen des Datenbanktyps.
     *
     * @return list<string>
     */
    public function schemas(DatabaseConnection $connection): array
    {
        $schemas = $this->registry->connection($connection)->getSchemaBuilder()->getSchemas();

        $names = array_values(array_filter(
            array_map(static fn (array $schema): string => (string) $schema['name'], $schemas),
            static fn (string $name): bool => ! $connection->driver->isSystemSchema($name),
        ));
        sort($names);

        return $names;
    }

    private function run(DatabaseConnection $connection): ConnectionTestResult
    {
        // Immer neu verbinden, damit geänderte Zugangsdaten wirklich geprüft werden.
        $this->registry->forget($connection);

        try {
            $database = $this->registry->connection($connection);
            $database->getPdo();
            $version = $database->getServerVersion();
            $schemas = $this->schemas($connection);
            $schema = $connection->schemaName();

            if (! in_array($schema, $schemas, true)) {
                return new ConnectionTestResult(false, __('db_connections.errors.unknown_schema', ['schema' => $schema]), $version, $schemas);
            }

            $builder = $database->getSchemaBuilder();

            return new ConnectionTestResult(true, __('db_connections.test.details', [
                'version' => $version,
                'schema' => $schema,
                'tables' => count($builder->getTables($schema)),
                'views' => count($builder->getViews($schema)),
                'schemas' => implode(', ', $schemas),
            ]), $version, $schemas);
        } catch (InvalidArgumentException $e) {
            return new ConnectionTestResult(false, __('db_connections.errors.invalid_settings'));
        } catch (Throwable $e) {
            return new ConnectionTestResult(false, $this->describe($e, $connection));
        } finally {
            $this->registry->forget($connection);
        }
    }

    /** Verständliche Meldung plus gekürzte technische Ursache, ohne Passwort. */
    private function describe(Throwable $e, DatabaseConnection $connection): string
    {
        $raw = $e->getMessage();
        $lower = mb_strtolower($raw);

        $key = match (true) {
            str_contains($lower, 'password authentication failed'), str_contains($lower, 'no password supplied'), str_contains($lower, 'access denied for user'),
            str_contains($lower, 'login failed for user') => 'auth',
            // SQL Server: „Cannot open database … requested by the login“ (fehlt oder kein Zugriff).
            str_contains($lower, 'cannot open database') => 'unknown_database',
            str_contains($lower, 'timeout expired'), str_contains($lower, 'timed out') => 'timeout',
            // ODBC-Treiber (SQL Server): TCP Provider 0x2AF9 = Host unbekannt, 0x274D = abgewiesen.
            str_contains($lower, 'could not translate host name'), str_contains($lower, 'name or service not known'), str_contains($lower, '0x2af9') => 'unknown_host',
            str_contains($lower, 'connection refused'), str_contains($lower, 'no route to host'), str_contains($lower, 'network is unreachable'), str_contains($lower, '0x274d') => 'unreachable',
            str_contains($lower, 'does not exist') && str_contains($lower, 'database'), str_contains($lower, 'unknown database') => 'unknown_database',
            str_contains($lower, 'ssl') => 'ssl',
            default => 'generic',
        };

        $message = __("db_connections.errors.{$key}", [
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $connection->database,
            'seconds' => $connection->connect_timeout,
        ]);

        // Erste Zeile der Ursache ohne SQL-Kontext; Passwort entfernen, falls es je auftaucht.
        $detail = Str::of($raw)->before("\n")->before(' (Connection:')->limit(300)->toString();

        if (filled($connection->password)) {
            $detail = str_replace((string) $connection->password, '***', $detail);
        }

        return $message.' ('.$detail.')';
    }
}
