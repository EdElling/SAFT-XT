<?php

declare(strict_types=1);

namespace App\ExternalData;

/**
 * Datenbanktyp einer externen Verbindung. „Schema“ heißt bei MySQL „Datenbank“: Das Vorgabeschema
 * ist dort die Datenbank der Verbindung, weitere Datenbanken desselben Servers sind weitere Schemas.
 * SQL Server kennt wie PostgreSQL Schemas innerhalb der Datenbank (Vorgabe dbo). MariaDB verhält sich
 * wie MySQL (Schema = Datenbank), mit eigenen Sitzungsvariablen und Typen.
 */
enum ConnectionDriver: string
{
    case Pgsql = 'pgsql';
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
    case Sqlsrv = 'sqlsrv';

    public function label(): string
    {
        return match ($this) {
            self::Pgsql => 'PostgreSQL',
            self::Mysql => 'MySQL',
            self::Mariadb => 'MariaDB',
            self::Sqlsrv => 'Microsoft SQL Server',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Pgsql => 5432,
            self::Mysql, self::Mariadb => 3306,
            self::Sqlsrv => 1433,
        };
    }

    /**
     * TLS-Modi. MySQL und SQL Server: require = verschlüsselt ohne Zertifikatsprüfung, verify-full =
     * mit Prüfung des Zertifikats (MySQL gegen die CA-Datei des Systems).
     *
     * @return list<string>
     */
    public function sslModes(): array
    {
        return match ($this) {
            self::Pgsql => ['disable', 'prefer', 'require', 'verify-ca', 'verify-full'],
            self::Mysql, self::Mariadb, self::Sqlsrv => ['disable', 'require', 'verify-full'],
        };
    }

    public function defaultSslMode(): string
    {
        return match ($this) {
            self::Pgsql => 'prefer',
            self::Mysql, self::Mariadb, self::Sqlsrv => 'require',
        };
    }

    /** Vorgabeschema, wenn keines eingetragen ist. */
    public function defaultSchema(string $database): string
    {
        return match ($this) {
            self::Pgsql => 'public',
            self::Mysql, self::Mariadb => $database,
            self::Sqlsrv => 'dbo',
        };
    }

    /** Interne Schemas (bei MySQL: Systemdatenbanken; SQL Server: sys und Rollen-Schemas), die nie angeboten werden. */
    public function isSystemSchema(string $name): bool
    {
        return match ($this) {
            self::Pgsql => in_array($name, ['information_schema', 'pg_catalog', 'pg_toast'], true) || str_starts_with($name, 'pg_'),
            self::Mysql, self::Mariadb => in_array(strtolower($name), ['information_schema', 'mysql', 'performance_schema', 'sys'], true),
            self::Sqlsrv => in_array(strtolower($name), ['information_schema', 'sys', 'guest'], true) || str_starts_with(strtolower($name), 'db_'),
        };
    }

    /** Übersetzt CHECK-Regeln dieses Datenbanktyps in Prüfungen von SAFT. */
    public function checkTranslator(): CheckTranslator
    {
        return match ($this) {
            self::Pgsql => new PostgresCheckTranslator,
            self::Mysql, self::Mariadb => new MySqlCheckTranslator,
            self::Sqlsrv => new SqlServerCheckTranslator,
        };
    }

    /** MySQL und MariaDB: Schema = Datenbank, gleiche information_schema und Werte-Typen (enum). */
    public function isMySqlFamily(): bool
    {
        return $this === self::Mysql || $this === self::Mariadb;
    }

    /** PHP-Treiber vorhanden? MariaDB nutzt pdo_mysql, SQL Server die optionale Erweiterung pdo_sqlsrv. */
    public function isAvailable(): bool
    {
        return in_array($this->isMySqlFamily() ? 'mysql' : $this->value, \PDO::getAvailableDrivers(), true);
    }

    /** „Nur lesen“ setzt die Datenbank selbst durch (SQL Server kennt das nicht je Verbindung). */
    public function enforcesReadOnly(): bool
    {
        return $this !== self::Sqlsrv;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
